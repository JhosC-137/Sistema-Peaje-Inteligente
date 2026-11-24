import re
import time
import cv2
import torch
import numpy as np
import onnxruntime as ort
from pathlib import Path
from fastapi import FastAPI, File, UploadFile, HTTPException, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from ultralytics import YOLO
from fast_plate_ocr import LicensePlateRecognizer

app = FastAPI(title="Smart Toll Inference API", version="1.1.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

DEVICE = "cuda:0" if torch.cuda.is_available() else "cpu"
WEIGHTS_DIR = Path(__file__).parent / "weights"

# Rutas de modelos M1 (ONNX) y M2/M3 (YOLOv11)
M1_DET_PATH = WEIGHTS_DIR / "yolo-v9-t-384-license-plates-end2end.onnx"
M1_OCR_PATH = WEIGHTS_DIR / "cct_xs_v2_global.onnx"
M1_CFG_PATH = WEIGHTS_DIR / "cct_xs_v2_global_plate_config.yaml"
M2_PATH = WEIGHTS_DIR / "m2_vehiculos.pt"
M3_PATH = WEIGHTS_DIR / "m3_ejes.pt"

# Inicializar M1 Detector ONNX + OCR especializado
providers = ["CUDAExecutionProvider", "CPUExecutionProvider"] if torch.cuda.is_available() else ["CPUExecutionProvider"]
det_placas = ort.InferenceSession(str(M1_DET_PATH), providers=providers) if M1_DET_PATH.exists() else None
ocr_placas = (
    LicensePlateRecognizer(
        onnx_model_path=str(M1_OCR_PATH),
        plate_config_path=str(M1_CFG_PATH),
    )
    if (M1_OCR_PATH.exists() and M1_CFG_PATH.exists())
    else None
)

# Inicializar M2 y M3 (con fallback hasta entrenar los pesos propios)
modelo_m2 = YOLO(str(M2_PATH)) if M2_PATH.exists() else YOLO("yolo11n-seg.pt")
modelo_m3 = YOLO(str(M3_PATH)) if M3_PATH.exists() else None

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
    """Limpia padding '_' y corrige confusiones comunes en formato boliviano 1234ABC."""
    limpio = re.sub(r"[^A-Z0-9]", "", texto_crudo.replace("_", "").upper())
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


def _letterbox_384(img: np.ndarray, size: int = 384):
    h, w = img.shape[:2]
    scale = min(size / w, size / h)
    nw, nh = int(round(w * scale)), int(round(h * scale))
    resized = cv2.resize(img, (nw, nh), interpolation=cv2.INTER_LINEAR)
    canvas = np.full((size, size, 3), 114, dtype=np.uint8)
    dw, dh = (size - nw) // 2, (size - nh) // 2
    canvas[dh : dh + nh, dw : dw + nw] = resized
    rgb = cv2.cvtColor(canvas, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
    tensor = np.transpose(rgb, (2, 0, 1))[None, ...]
    return tensor, scale, dw, dh


def _detectar_placa_y_ocr_detalle(img: np.ndarray) -> tuple[str, str, float, bool, list[int] | None]:
    """Etapa 1: Detecta placa con YOLOv9 ONNX, lee caracteres con CCT-XS-V2 y retorna su bbox."""
    roi = img
    placa_detectada = False
    bbox_placa: list[int] | None = None
    h, w = img.shape[:2]

    if det_placas is not None:
        tensor, scale, dw, dh = _letterbox_384(img, 384)
        input_name = det_placas.get_inputs()[0].name
        dets = det_placas.run(None, {input_name: tensor})[0]

        if dets is not None and len(dets) > 0:
            if dets.shape[-1] == 7:
                scores = dets[:, 6]
                boxes = dets[:, 1:5]
            else:
                scores = dets[:, 4]
                boxes = dets[:, :4]

            validos = np.where(scores >= 0.35)[0]
            if len(validos) > 0:
                mejor = validos[int(np.argmax(scores[validos]))]
                bx1, by1, bx2, by2 = boxes[mejor]
                pad = 6
                x1 = int(max(0, (bx1 - dw) / scale - pad))
                y1 = int(max(0, (by1 - dh) / scale - pad))
                x2 = int(min(w, (bx2 - dw) / scale + pad))
                y2 = int(min(h, (by2 - dh) / scale + pad))
                if x2 > x1 and y2 > y1:
                    roi = img[y1:y2, x1:x2]
                    placa_detectada = True
                    bbox_placa = [x1, y1, x2, y2]

    if ocr_placas is None or not placa_detectada:
        return "ILEGIBLE", "ILEGIBLE", 0.0, placa_detectada, bbox_placa

    try:
        res_ocr = ocr_placas.run(roi, return_confidence=True)
    except TypeError:
        res_ocr = ocr_placas.run(roi)

    if not res_ocr:
        return "ILEGIBLE", "ILEGIBLE", 0.0, placa_detectada, bbox_placa

    if isinstance(res_ocr, tuple) and len(res_ocr) == 2:
        lecturas, probs = res_ocr
        texto_raw = str(lecturas[0]) if len(lecturas) > 0 else ""
        confianza_ocr = float(np.mean(probs[0])) if len(probs) > 0 else 0.0
    else:
        pred = res_ocr[0]
        if hasattr(pred, "plate"):
            texto_raw = str(pred.plate)
            char_probs = getattr(pred, "char_probs", None)
            if char_probs is None:
                char_probs = getattr(pred, "confidence", None)
            confianza_ocr = float(np.mean(char_probs)) if char_probs is not None and len(char_probs) > 0 else 0.0
        elif isinstance(pred, (list, tuple)) and len(pred) == 2:
            texto_raw = str(pred[0])
            confianza_ocr = float(np.mean(pred[1]))
        else:
            texto_raw = str(pred)
            confianza_ocr = 0.0

    # Eliminar caracteres de relleno '_' propios del modelo CCT
    placa_leida = texto_raw.replace("_", "").strip()

    if not placa_leida:
        return "ILEGIBLE", "ILEGIBLE", 0.0, placa_detectada, bbox_placa

    placa_norm = normalizar_placa_bolivia(placa_leida) or "ILEGIBLE"
    return placa_leida, placa_norm, round(confianza_ocr, 4), placa_detectada, bbox_placa


def detectar_placa_y_ocr(img: np.ndarray) -> tuple[str, str, float, bool]:
    """Etapa 1: Detecta placa con YOLOv9 ONNX y lee caracteres con CCT-XS-V2."""
    placa_leida, placa_norm, confianza_ocr, placa_detectada, _ = _detectar_placa_y_ocr_detalle(img)
    return placa_leida, placa_norm, confianza_ocr, placa_detectada


UMBRAL_CONTENCION = 0.70
UMBRAL_CERCANA = 0.58
SEPARACION_EJE = 0.52


def clasificar_vehiculo(img: np.ndarray) -> tuple[str, float, list[float]]:
    """Etapa 2: Retorna categoría, confianza y caja [x1, y1, x2, y2] del vehículo principal."""
    res_m2 = modelo_m2.predict(img, conf=0.40, device=DEVICE, verbose=False)[0]
    if res_m2.boxes is None or len(res_m2.boxes) == 0:
        return "auto", 0.0, [0.0, 0.0, float(img.shape[1]), float(img.shape[0])]

    cajas = res_m2.boxes.xyxy.cpu().numpy()
    areas = (cajas[:, 2] - cajas[:, 0]) * (cajas[:, 3] - cajas[:, 1])
    idx = int(np.argmax(areas))

    cls_id = int(res_m2.boxes.cls[idx].item())
    conf = float(res_m2.boxes.conf[idx].item())
    nombre_clase = str(res_m2.names[cls_id]).lower().strip()
    categoria = MAPEO_CATEGORIAS.get(nombre_clase, "auto")
    return categoria, round(conf, 4), cajas[idx].tolist()


def _contenida(a: list[float], b: list[float]) -> float:
    x1, y1 = max(a[0], b[0]), max(a[1], b[1])
    x2, y2 = min(a[2], b[2]), min(a[3], b[3])
    inter = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    area = max(1e-6, (a[2] - a[0]) * (a[3] - a[1]))
    return inter / area


def contar_ejes(img: np.ndarray, categoria: str, bbox_vehiculo: list[float]) -> tuple[int, int]:
    """Etapa 3: Cuenta ejes asociando los 5 keypoints de llantas al vehículo principal."""
    if modelo_m3 is None:
        return (3 if categoria == "camion" else 2), 0

    res_m3 = modelo_m3.predict(img, conf=0.35, device=DEVICE, verbose=False)[0]
    if res_m3.keypoints is None or res_m3.boxes is None or len(res_m3.boxes) == 0:
        return 2, 0

    cajas = res_m3.boxes.xyxy.cpu().numpy()
    kpts_xy = res_m3.keypoints.xy.cpu().numpy()

    # 1. Descartar detecciones internas (ej. aros dentro de la llanta)
    orden = np.argsort(-((cajas[:, 2] - cajas[:, 0]) * (cajas[:, 3] - cajas[:, 1])))
    llantas = []
    for idx in orden:
        box = cajas[idx].tolist()
        if all(_contenida(box, r["box"]) < UMBRAL_CONTENCION for r in llantas):
            llantas.append({"box": box, "kps": kpts_xy[idx]})

    # 2. Filtrar solo llantas ubicadas en la parte inferior del vehículo principal
    vx1, vy1, vx2, vy2 = bbox_vehiculo
    vw, vh = vx2 - vx1, vy2 - vy1
    asociadas = [
        l for l in llantas
        if (vx1 - 0.05 * vw <= l["kps"][0, 0] <= vx2 + 0.05 * vw)
        and (vy1 + 0.35 * vh <= l["kps"][0, 1] <= vy2 + 0.10 * vh)
    ]
    if not asociadas:
        return 2, 0

    # 3. Descartar llantas del lado opuesto usando altura visible (contacto.y - superior.y)
    alturas = [max(1.0, float(l["kps"][1, 1] - l["kps"][2, 1])) for l in asociadas]
    hmax = max(alturas)
    cercanas = [l for l, h in zip(asociadas, alturas) if h >= UMBRAL_CERCANA * hmax]

    # 4. Agrupar ruedas gemelas según el diámetro mediano de llanta
    xs = sorted(float(l["kps"][0, 0]) for l in cercanas)
    diam = float(np.median([max(1.0, float(l["kps"][1, 1] - l["kps"][2, 1])) for l in cercanas]))
    ejes = 1 + sum(1 for p, q in zip(xs, xs[1:]) if (q - p) > SEPARACION_EJE * diam)

    ejes = max(2, min(6, ejes))
    if categoria in ("moto", "auto", "minibus") and ejes > 2:
        ejes = 2

    return ejes, len(cercanas)


@app.get("/health")
def health():
    return {
        "status": "ok",
        "device": DEVICE,
        "modelos_cargados": {
            "m1_detector_onnx": det_placas is not None,
            "m1_ocr_onnx": ocr_placas is not None,
            "m2_vehiculos": M2_PATH.exists(),
            "m3_ejes": M3_PATH.exists(),
        },
    }


@app.post("/ocr")
async def solo_ocr(imagen: UploadFile = File(...)):
    t0 = time.perf_counter()
    contenido = await imagen.read()
    arr = np.frombuffer(contenido, np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)

    if img is None:
        raise HTTPException(status_code=400, detail="No se pudo decodificar la imagen enviada.")

    placa_leida, placa_normalizada, confianza_ocr, m1_ok, _ = _detectar_placa_y_ocr_detalle(img)
    return {
        "placa_leida": placa_leida,
        "placa_normalizada": placa_normalizada,
        "confianza_ocr": confianza_ocr,
        "placa_detectada": m1_ok,
        "latencia": round((time.perf_counter() - t0) * 1000, 2),
    }


@app.post("/inferir")
async def inferir(imagen: UploadFile = File(...)):
    t0 = time.perf_counter()
    contenido = await imagen.read()
    arr = np.frombuffer(contenido, np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)

    if img is None:
        raise HTTPException(status_code=400, detail="No se pudo decodificar la imagen enviada.")

    placa_leida, placa_normalizada, confianza_ocr, m1_ok = detectar_placa_y_ocr(img)
    categoria, conf_m2, bbox_vehiculo = clasificar_vehiculo(img)
    ejes, llantas_detectadas = contar_ejes(img, categoria, bbox_vehiculo)
    latencia_ms = round((time.perf_counter() - t0) * 1000, 2)

    return {
        "placa_leida": placa_leida,
        "placa_normalizada": placa_normalizada,
        "confianza_ocr": confianza_ocr,
        "categoria_detectada": categoria,
        "ejes_detectados": ejes,
        "pasajeros": 0,
        "latencia": latencia_ms,
        "detalles": {
            "device": DEVICE,
            "m1_placa_detectada": m1_ok,
            "m2_confianza": conf_m2,
            "m3_llantas_detectadas": llantas_detectadas,
            "latencia_ms": latencia_ms,
        },
    }


def analizar_frame_vigilancia(img: np.ndarray) -> dict:
    """Ejecuta M1, M2 y M3 y devuelve cajas normalizadas (0.0 a 1.0) para overlay en vivo."""
    h, w = img.shape[:2]
    categoria, conf_m2, bbox_v = clasificar_vehiculo(img)
    ejes, llantas_det = contar_ejes(img, categoria, bbox_v)
    _, placa_norm, conf_ocr, m1_ok, bbox_p = _detectar_placa_y_ocr_detalle(img)

    bbox_placa_norm = None
    if m1_ok and bbox_p is not None and w > 0 and h > 0:
        x1, y1, x2, y2 = bbox_p
        bbox_placa_norm = [round(x1 / w, 4), round(y1 / h, 4), round(x2 / w, 4), round(y2 / h, 4)]

    bbox_vehiculo_norm = None
    if conf_m2 > 0.40 and w > 0 and h > 0:
        vx1, vy1, vx2, vy2 = bbox_v
        bbox_vehiculo_norm = [round(vx1 / w, 4), round(vy1 / h, 4), round(vx2 / w, 4), round(vy2 / h, 4)]

    return {
        "vehiculo_detectado": conf_m2 > 0.40,
        "categoria": categoria,
        "conf_vehiculo": conf_m2,
        "bbox_vehiculo": bbox_vehiculo_norm,
        "placa_detectada": m1_ok,
        "placa": placa_norm,
        "conf_ocr": conf_ocr,
        "bbox_placa": bbox_placa_norm,
        "ejes": ejes,
        "llantas": llantas_det,
    }


@app.websocket("/ws/vigilancia")
async def ws_vigilancia(websocket: WebSocket):
    await websocket.accept()
    try:
        while True:
            frame_bytes = await websocket.receive_bytes()
            arr = np.frombuffer(frame_bytes, np.uint8)
            img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
            if img is None:
                await websocket.send_json({"error": "frame_invalido"})
                continue
            resultado = analizar_frame_vigilancia(img)
            await websocket.send_json(resultado)
    except WebSocketDisconnect:
        pass