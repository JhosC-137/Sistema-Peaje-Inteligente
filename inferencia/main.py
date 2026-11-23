import os
import re
import cv2
import torch
import easyocr
import numpy as np
from pathlib import Path
from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from ultralytics import YOLO

app = FastAPI(title="Smart Toll Inference API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

DEVICE = "cuda:0" if torch.cuda.is_available() else "cpu"
WEIGHTS_DIR = Path(__file__).parent / "weights"

# Rutas de los 3 modelos entrenados
M1_PATH = WEIGHTS_DIR / "m1_placas.pt"
M2_PATH = WEIGHTS_DIR / "m2_vehiculos.pt"
M3_PATH = WEIGHTS_DIR / "m3_ejes.pt"

# Carga de modelos (con fallback si aún faltan M2 o M3)
modelo_m1 = YOLO(str(M1_PATH)) if M1_PATH.exists() else None
modelo_m2 = YOLO(str(M2_PATH)) if M2_PATH.exists() else YOLO("yolo11n-seg.pt")
modelo_m3 = YOLO(str(M3_PATH)) if M3_PATH.exists() else None

reader_ocr = easyocr.Reader(["en"], gpu=torch.cuda.is_available())

# Mapeo de clases hacia el ENUM exacto de PostgreSQL en Laravel
MAPEO_CATEGORIAS = {
    "moto": "moto",
    "motorcycle": "moto",
    "auto": "auto",
    "car": "auto",
    "vagoneta": "auto",
    "camioneta": "auto",
    "minibus": "minibus",
    "van": "minibus",
    "bus": "bus",
    "flota": "bus",
    "camion": "camion",
    "truck": "camion",
}


def normalizar_placa_bolivia(texto_crudo: str) -> str:
    """Limpia caracteres y corrige confusiones comunes en formato 1234ABC."""
    limpio = re.sub(r"[^A-Z0-9]", "", texto_crudo.upper())
    if len(limpio) == 7:
        nums = (
            limpio[:4]
            .replace("O", "0")
            .replace("Q", "0")
            .replace("I", "1")
            .replace("L", "1")
            .replace("Z", "2")
            .replace("S", "5")
            .replace("B", "8")
        )
        letras = (
            limpio[4:]
            .replace("0", "O")
            .replace("1", "I")
            .replace("2", "Z")
            .replace("5", "S")
            .replace("8", "B")
        )
        return f"{nums}{letras}"
    return limpio


def preprocesar_recorte_placa(crop: np.ndarray) -> np.ndarray:
    """Mejora contraste de la placa antes de pasarla a EasyOCR."""
    gris = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    h, w = gris.shape
    if w < 220:
        escala = 220.0 / max(w, 1)
        gris = cv2.resize(gris, None, fx=escala, fy=escala, interpolation=cv2.INTER_CUBIC)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    return clahe.apply(gris)


def detectar_placa_y_ocr(img: np.ndarray) -> tuple[str, str, float, bool]:
    """Etapa 1: Detecta ROI de placa con M1 y extrae texto con EasyOCR."""
    roi = img
    placa_detectada = False

    if modelo_m1 is not None:
        res_m1 = modelo_m1.predict(img, conf=0.35, device=DEVICE, verbose=False)[0]
        if res_m1.boxes is not None and len(res_m1.boxes) > 0:
            idx_mejor = int(res_m1.boxes.conf.argmax().item())
            x1, y1, x2, y2 = map(int, res_m1.boxes.xyxy[idx_mejor].tolist())
            pad = 8
            h, w, _ = img.shape
            x1, y1 = max(0, x1 - pad), max(0, y1 - pad)
            x2, y2 = min(w, x2 + pad), min(h, y2 + pad)
            if x2 > x1 and y2 > y1:
                roi = img[y1:y2, x1:x2]
                placa_detectada = True

    roi_proc = preprocesar_recorte_placa(roi)
    lecturas = reader_ocr.readtext(
        roi_proc,
        allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
        detail=1,
    )

    if not lecturas:
        return "ILEGIBLE", "ILEGIBLE", 0.0, placa_detectada

    # Ordenar fragmentos de izquierda a derecha y concatenar
    lecturas.sort(key=lambda item: item[0][0][0])
    texto_bruto = "".join(item[1] for item in lecturas).strip()
    confianza_media = float(np.mean([item[2] for item in lecturas]))
    placa_norm = normalizar_placa_bolivia(texto_bruto) or "ILEGIBLE"

    return texto_bruto, placa_norm, round(confianza_media, 4), placa_detectada


def clasificar_vehiculo(img: np.ndarray) -> tuple[str, float]:
    """Etapa 2: Clasifica categoría vehicular con M2 (yolo11-seg)."""
    res_m2 = modelo_m2.predict(img, conf=0.40, device=DEVICE, verbose=False)[0]
    if res_m2.boxes is None or len(res_m2.boxes) == 0:
        return "auto", 0.0

    # Seleccionar el vehículo con mayor área en cuadro (el más cercano a la caseta)
    cajas = res_m2.boxes.xyxy.cpu().numpy()
    areas = (cajas[:, 2] - cajas[:, 0]) * (cajas[:, 3] - cajas[:, 1])
    idx = int(np.argmax(areas))

    cls_id = int(res_m2.boxes.cls[idx].item())
    conf = float(res_m2.boxes.conf[idx].item())
    nombre_clase = str(res_m2.names[cls_id]).lower().strip()

    categoria = MAPEO_CATEGORIAS.get(nombre_clase, "auto")
    return categoria, round(conf, 4)


def contar_ejes(img: np.ndarray, categoria: str) -> tuple[int, int]:
    """Etapa 3: Detecta keypoints de llantas visibles con M3 (yolo11-pose)."""
    if modelo_m3 is None:
        return (3 if categoria == "camion" else 2), 0

    res_m3 = modelo_m3.predict(img, conf=0.40, device=DEVICE, verbose=False)[0]
    if res_m3.keypoints is None or len(res_m3.keypoints.xy) == 0:
        return 2, 0

    # Tomar los keypoints de la instancia principal
    pts = res_m3.keypoints.xy[0].cpu().numpy()
    confs = (
        res_m3.keypoints.conf[0].cpu().numpy()
        if res_m3.keypoints.conf is not None
        else np.ones(len(pts))
    )

    xs_validos = sorted([float(pt[0]) for pt, c in zip(pts, confs) if c >= 0.45 and pt[0] > 0])
    total_llantas = len(xs_validos)

    if total_llantas == 0:
        return 2, 0

    # Agrupar coordenadas X muy cercanas (ej. doble eje trasero o doble detección)
    umbral_x = img.shape[1] * 0.04
    ejes_agrupados = [xs_validos[0]]
    for x in xs_validos[1:]:
        if abs(x - ejes_agrupados[-1]) > umbral_x:
            ejes_agrupados.append(x)

    ejes = max(2, min(6, len(ejes_agrupados)))
    if categoria in ("moto", "auto", "minibus") and ejes > 2:
        ejes = 2

    return ejes, total_llantas


@app.get("/health")
def health():
    return {
        "status": "ok",
        "device": DEVICE,
        "modelos_cargados": {
            "m1_placas": M1_PATH.exists(),
            "m2_vehiculos": M2_PATH.exists(),
            "m3_ejes": M3_PATH.exists(),
        },
    }


@app.post("/inferir")
async def inferir(imagen: UploadFile = File(...)):
    contenido = await imagen.read()
    arr = np.frombuffer(contenido, np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)

    if img is None:
        raise HTTPException(status_code=400, detail="No se pudo decodificar la imagen enviada.")

    placa_leida, placa_normalizada, confianza_ocr, m1_ok = detectar_placa_y_ocr(img)
    categoria, conf_m2 = clasificar_vehiculo(img)
    ejes, llantas_detectadas = contar_ejes(img, categoria)

    return {
        "placa_leida": placa_leida,
        "placa_normalizada": placa_normalizada,
        "confianza_ocr": confianza_ocr,
        "categoria_detectada": categoria,
        "ejes_detectados": ejes,
        "pasajeros": 0,
        "detalles": {
            "device": DEVICE,
            "m1_placa_detectada": m1_ok,
            "m2_confianza": conf_m2,
            "m3_llantas_detectadas": llantas_detectadas,
        },
    }