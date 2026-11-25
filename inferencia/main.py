import re
import os
import time
import base64
import tempfile
from collections import Counter, deque
import cv2
import torch
import numpy as np
import onnxruntime as ort
from pathlib import Path
from fastapi import FastAPI, File, UploadFile, HTTPException, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from ultralytics import YOLO
from fast_plate_ocr import LicensePlateRecognizer

app = FastAPI(title="Smart Toll Inference API", version="1.2.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

DEVICE = "cuda:0" if torch.cuda.is_available() else "cpu"
BASE_DIR = Path(__file__).parent
WEIGHTS_DIR = BASE_DIR / "weights"

# Rutas de modelos M1 (ONNX), M2/M3 (YOLOv11) y M4 Pasajeros (YOLOv11-seg)
M1_DET_PATH = WEIGHTS_DIR / "yolo-v9-t-384-license-plates-end2end.onnx"
M1_OCR_PATH = WEIGHTS_DIR / "cct_xs_v2_global.onnx"
M1_CFG_PATH = WEIGHTS_DIR / "cct_xs_v2_global_plate_config.yaml"
M2_PATH = WEIGHTS_DIR / "m2_vehiculos.pt"
M3_PATH = WEIGHTS_DIR / "m3_llantas.pt"
M3_ALT_PATH = WEIGHTS_DIR / "m3_ejes.pt"
M4_PATH = WEIGHTS_DIR / "m4_pasajeros_seg.pt"
M4_ALT_PATH = WEIGHTS_DIR / "m4_pasajeros.pt"
YOLO_SEG_FALLBACK = BASE_DIR / "yolo11n-seg.pt"

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

# Inicializar M2 (vehículos seg), M3 (llantas keypoints) y M4 (pasajeros seg)
modelo_m2 = YOLO(str(M2_PATH)) if M2_PATH.exists() else YOLO(str(YOLO_SEG_FALLBACK) if YOLO_SEG_FALLBACK.exists() else "yolo11n-seg.pt")
_m3_peso = M3_PATH if M3_PATH.exists() else (M3_ALT_PATH if M3_ALT_PATH.exists() else None)
modelo_m3 = YOLO(str(_m3_peso)) if _m3_peso is not None else None
_m4_peso = M4_PATH if M4_PATH.exists() else (M4_ALT_PATH if M4_ALT_PATH.exists() else None)
modelo_pasajeros = YOLO(str(_m4_peso)) if _m4_peso is not None else None
USE_HALF = DEVICE.startswith("cuda")

# Warmup inicial para evitar latencia alta en la primera petición
try:
    _dummy = np.zeros((384, 384, 3), dtype=np.uint8)
    modelo_m2.predict(_dummy, imgsz=384, conf=0.25, device=DEVICE, half=USE_HALF, verbose=False)
    if modelo_m3 is not None:
        modelo_m3.predict(_dummy, imgsz=384, conf=0.25, device=DEVICE, half=USE_HALF, verbose=False)
    if modelo_pasajeros is not None:
        modelo_pasajeros.predict(_dummy, imgsz=384, conf=0.30, device=DEVICE, half=USE_HALF, verbose=False)
except Exception:
    pass

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


def _aplicar_clahe(roi: np.ndarray) -> np.ndarray:
    """Normaliza iluminación y reflejos en el recorte de placa antes del OCR."""
    if roi.size == 0:
        return roi
    lab = cv2.cvtColor(roi, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    l = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(4, 4)).apply(l)
    return cv2.cvtColor(cv2.merge((l, a, b)), cv2.COLOR_LAB2BGR)


def _ejecutar_ocr_roi(roi: np.ndarray) -> tuple[str, str, float]:
    """Ejecuta fast-plate-ocr sobre un recorte de placa y retorna (crudo, normalizado, confianza)."""
    if ocr_placas is None or roi.size == 0:
        return "ILEGIBLE", "ILEGIBLE", 0.0

    roi_norm = _aplicar_clahe(roi)
    try:
        res_ocr = ocr_placas.run(roi_norm, return_confidence=True)
    except TypeError:
        res_ocr = ocr_placas.run(roi_norm)

    if not res_ocr:
        return "ILEGIBLE", "ILEGIBLE", 0.0

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

    placa_leida = texto_raw.replace("_", "").strip()
    if not placa_leida:
        return "ILEGIBLE", "ILEGIBLE", 0.0

    placa_norm = normalizar_placa_bolivia(placa_leida) or "ILEGIBLE"
    return placa_leida, placa_norm, round(confianza_ocr, 4)


def _detectar_placa_y_ocr_detalle(img: np.ndarray) -> tuple[str, str, float, bool, list[int] | None]:
    """Etapa 1: Detecta placa con YOLOv9 ONNX, mejora contraste con CLAHE y lee caracteres."""
    if det_placas is None or img.size == 0:
        return "ILEGIBLE", "ILEGIBLE", 0.0, False, None

    h, w = img.shape[:2]
    tensor, scale, dw, dh = _letterbox_384(img, 384)
    input_name = det_placas.get_inputs()[0].name
    dets = det_placas.run(None, {input_name: tensor})[0]

    if dets is None or len(dets) == 0:
        return "ILEGIBLE", "ILEGIBLE", 0.0, False, None

    if dets.shape[-1] == 7:
        scores = dets[:, 6]
        boxes = dets[:, 1:5]
    else:
        scores = dets[:, 4]
        boxes = dets[:, :4]

    validos = np.where(scores >= 0.28)[0]
    if len(validos) == 0:
        return "ILEGIBLE", "ILEGIBLE", 0.0, False, None

    mejor = validos[int(np.argmax(scores[validos]))]
    bx1, by1, bx2, by2 = boxes[mejor]
    x1_raw = max(0.0, (bx1 - dw) / scale)
    y1_raw = max(0.0, (by1 - dh) / scale)
    x2_raw = min(float(w), (bx2 - dw) / scale)
    y2_raw = min(float(h), (by2 - dh) / scale)

    pad_x = max(4.0, (x2_raw - x1_raw) * 0.08)
    pad_y = max(4.0, (y2_raw - y1_raw) * 0.12)
    x1 = int(max(0, x1_raw - pad_x))
    y1 = int(max(0, y1_raw - pad_y))
    x2 = int(min(w, x2_raw + pad_x))
    y2 = int(min(h, y2_raw + pad_y))

    if x2 <= x1 or y2 <= y1:
        return "ILEGIBLE", "ILEGIBLE", 0.0, False, None

    roi = img[y1:y2, x1:x2]
    bbox_placa = [x1, y1, x2, y2]
    placa_leida, placa_norm, confianza_ocr = _ejecutar_ocr_roi(roi)
    return placa_leida, placa_norm, confianza_ocr, True, bbox_placa


UMBRAL_CONTENCION = 0.70
UMBRAL_CERCANA = 0.58
SEPARACION_EJE = 0.52


def _contenida(a: list[float], b: list[float]) -> float:
    x1, y1 = max(a[0], b[0]), max(a[1], b[1])
    x2, y2 = min(a[2], b[2]), min(a[3], b[3])
    inter = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    area = max(1e-6, (a[2] - a[0]) * (a[3] - a[1]))
    return inter / area


def contar_ejes_en_recorte(
    crop_v: np.ndarray, categoria: str, imgsz: int = 416
) -> tuple[int, int, list[list[float]], list[list[float]], list[dict]]:
    """Etapa 3: Cuenta ejes sobre el recorte del vehículo usando M3 (keypoints/cajas de llantas)."""
    ch, cw = crop_v.shape[:2]
    if ch < 30 or cw < 30 or modelo_m3 is None:
        return (3 if categoria in ("bus", "camion") else 2), 0, [], [], []

    res_m3 = modelo_m3.predict(
        crop_v, imgsz=imgsz, conf=0.25, iou=0.45, device=DEVICE, half=USE_HALF, verbose=False
    )[0]
    if res_m3.boxes is None or len(res_m3.boxes) == 0:
        return (3 if categoria in ("bus", "camion") else 2), 0, [], [], []

    cajas = res_m3.boxes.xyxy.cpu().numpy()
    kpts_xy = res_m3.keypoints.xy.cpu().numpy() if getattr(res_m3, "keypoints", None) is not None and res_m3.keypoints is not None else None
    kpts_conf = res_m3.keypoints.conf.cpu().numpy() if kpts_xy is not None and res_m3.keypoints.conf is not None else None

    # 1. Descartar detecciones internas (aros dentro de llanta)
    orden = np.argsort(-((cajas[:, 2] - cajas[:, 0]) * (cajas[:, 3] - cajas[:, 1])))
    llantas = []
    for idx in orden:
        box = cajas[idx].tolist()
        if all(_contenida(box, r["box"]) < UMBRAL_CONTENCION for r in llantas):
            kps = kpts_xy[idx] if kpts_xy is not None else None
            kconf = float(kpts_conf[idx][0]) if kpts_conf is not None else float(res_m3.boxes.conf[idx].item())
            llantas.append({"box": box, "kps": kps, "conf": kconf})

    # 2. Filtrar solo llantas en la parte inferior del vehículo (ignorar espejos/luces)
    asociadas = []
    for l in llantas:
        bx1, by1, bx2, by2 = l["box"]
        cx = float(l["kps"][0, 0]) if l["kps"] is not None and l["kps"][0, 0] > 0 else (bx1 + bx2) / 2.0
        cy = float(l["kps"][0, 1]) if l["kps"] is not None and l["kps"][0, 1] > 0 else (by1 + by2) / 2.0
        if cy >= ch * 0.40:
            l["cx"] = cx
            l["cy"] = cy
            l["vis_h"] = (
                max(1.0, float(l["kps"][1, 1] - l["kps"][2, 1]))
                if l["kps"] is not None and len(l["kps"]) >= 3 and l["kps"][1, 1] > l["kps"][2, 1]
                else max(1.0, by2 - by1)
            )
            asociadas.append(l)

    if not asociadas:
        return (3 if categoria in ("bus", "camion") else 2), 0, [], [], []

    # 3. Filtrar lado cercano usando altura visible
    hmax = max(l["vis_h"] for l in asociadas)
    cercanas = [l for l in asociadas if l["vis_h"] >= UMBRAL_CERCANA * hmax]
    cercanas.sort(key=lambda l: l["cx"])

    # 4. Agrupar llantas gemelas por separación horizontal y conservar 1 keypoint por eje
    diam = float(np.median([l["vis_h"] for l in cercanas]))
    umbral_sep = max(cw * 0.08, SEPARACION_EJE * diam)
    ejes_unicos = [cercanas[0]]
    for l in cercanas[1:]:
        if abs(l["cx"] - ejes_unicos[-1]["cx"]) > umbral_sep:
            ejes_unicos.append(l)

    ejes = max(2, min(6, len(ejes_unicos)))
    if categoria in ("moto", "auto", "minibus") and ejes > 2:
        ejes = 2

    puntos_rel = [[round(l["cx"] / cw, 4), round(l["cy"] / ch, 4)] for l in cercanas]
    keypoints_ejes_rel = [
        {"x": round(l["cx"] / cw, 4), "y": round(l["cy"] / ch, 4), "conf": round(l["conf"], 3)}
        for l in ejes_unicos
    ]
    boxes_rel = [
        [
            round(max(0.0, l["box"][0]) / cw, 4),
            round(max(0.0, l["box"][1]) / ch, 4),
            round(min(float(cw), l["box"][2]) / cw, 4),
            round(min(float(ch), l["box"][3]) / ch, 4),
        ]
        for l in cercanas
    ]
    return ejes, len(cercanas), puntos_rel, boxes_rel, keypoints_ejes_rel


def segmentar_pasajeros_en_recorte(
    crop_v: np.ndarray, imgsz: int = 416
) -> tuple[int, list[list[list[float]]]]:
    """Etapa 4: Segmenta y cuenta pasajeros visibles dentro del vehículo (sin rostros)."""
    ch, cw = crop_v.shape[:2]
    if ch < 35 or cw < 35 or modelo_pasajeros is None:
        return 0, []

    res_p = modelo_pasajeros.predict(
        crop_v, imgsz=imgsz, conf=0.20, device=DEVICE, half=USE_HALF, verbose=False
    )[0]
    if res_p.boxes is None or len(res_p.boxes) == 0:
        return 0, []

    mascaras_rel: list[list[list[float]]] = []
    boxes = res_p.boxes.xyxy.cpu().numpy()
    masks_xyn = res_p.masks.xyn if res_p.masks is not None else None

    for i, box in enumerate(boxes):
        px1, py1, px2, py2 = box.tolist()
        cy = (py1 + py2) / 2.0
        area_rel = ((px2 - px1) * (py2 - py1)) / max(1.0, cw * ch)
        # Filtrar zona de cabina/ventanas (78% superior) y descartar ruido diminuto
        if cy > ch * 0.78 or area_rel < 0.001:
            continue

        if masks_xyn is not None and i < len(masks_xyn) and len(masks_xyn[i]) >= 3:
            poly = masks_xyn[i]
            step = max(1, len(poly) // 24)
            mascaras_rel.append([[round(float(p[0]), 4), round(float(p[1]), 4)] for p in poly[::step]])
        else:
            mascaras_rel.append([
                [round(px1 / cw, 4), round(py1 / ch, 4)],
                [round(px2 / cw, 4), round(py1 / ch, 4)],
                [round(px2 / cw, 4), round(py2 / ch, 4)],
                [round(px1 / cw, 4), round(py2 / ch, 4)],
            ])

    return len(mascaras_rel), mascaras_rel


def calcular_iou(b1: list[float], b2: list[float]) -> float:
    xA, yA = max(b1[0], b2[0]), max(b1[1], b2[1])
    xB, yB = min(b1[2], b2[2]), min(b1[3], b2[3])
    inter = max(0.0, xB - xA) * max(0.0, yB - yA)
    area1 = max(1e-6, (b1[2] - b1[0]) * (b1[3] - b1[1]))
    area2 = max(1e-6, (b2[2] - b2[0]) * (b2[3] - b2[1]))
    return inter / (area1 + area2 - inter)


class RastreadorVigilancia:
    """Estabiliza placa, ejes, categoría y pasajeros mediante consenso temporal por IoU."""

    def __init__(self):
        self.tracks: dict[int, dict] = {}
        self.next_id = 1

    def actualizar(self, detecciones_frame: list[dict]) -> list[dict]:
        nuevos_tracks: dict[int, dict] = {}
        salida: list[dict] = []

        for det in detecciones_frame:
            bbox = det["bbox_vehiculo"]
            mejor_id, mejor_iou = None, 0.35
            for tid, tr in self.tracks.items():
                iou = calcular_iou(bbox, tr["bbox"])
                if iou > mejor_iou:
                    mejor_iou, mejor_id = iou, tid

            if mejor_id is None:
                tid = self.next_id
                self.next_id += 1
                tr = {
                    "bbox": bbox,
                    "placas": deque(maxlen=6),
                    "ejes": deque(maxlen=6),
                    "categorias": deque(maxlen=6),
                    "pasajeros": deque(maxlen=6),
                }
            else:
                tid = mejor_id
                tr = self.tracks[tid]
                tr["bbox"] = bbox

            placa_act = det.get("placa", "")
            if placa_act and placa_act != "ILEGIBLE" and det.get("conf_ocr", 0.0) >= 0.60:
                tr["placas"].append((placa_act, float(det["conf_ocr"])))
            tr["ejes"].append(int(det["ejes"]))
            tr["categorias"].append(str(det["categoria"]))
            tr["pasajeros"].append(int(det.get("pasajeros", 0)))

            ejes_estables = Counter(tr["ejes"]).most_common(1)[0][0]
            cat_estable = Counter(tr["categorias"]).most_common(1)[0][0]
            pasajeros_estables = Counter(tr["pasajeros"]).most_common(1)[0][0]

            placa_estable, conf_media, confirmado = "ILEGIBLE", 0.0, False
            if tr["placas"]:
                conteo = Counter(p for p, _ in tr["placas"])
                placa_estable, repeticiones = conteo.most_common(1)[0]
                confs = [c for p, c in tr["placas"] if p == placa_estable]
                conf_media = round(sum(confs) / len(confs), 3)
                confirmado = repeticiones >= 2 and conf_media >= 0.72

            nuevos_tracks[tid] = tr
            det.update({
                "track_id": tid,
                "categoria": cat_estable,
                "ejes": ejes_estables,
                "pasajeros": pasajeros_estables,
                "placa": placa_estable,
                "conf_ocr": conf_media,
                "confirmado": confirmado,
            })
            salida.append(det)

        self.tracks = nuevos_tracks
        return salida


rastreador_http = RastreadorVigilancia()


def _procesar_vehiculos_crop_first(
    img: np.ndarray, max_vehiculos: int = 2, imgsz_m2: int = 384, imgsz_m3: int = 320, imgsz_m4: int = 416
) -> list[dict]:
    """Detecta todos los vehículos con M2 y procesa ejes, placa y pasajeros por recorte."""
    h, w = img.shape[:2]
    with torch.inference_mode():
        res_m2 = modelo_m2.predict(
            img, imgsz=imgsz_m2, conf=0.30, iou=0.50, device=DEVICE, half=USE_HALF, verbose=False
        )[0]

    detecciones: list[dict] = []
    if res_m2.boxes is not None and len(res_m2.boxes) > 0:
        cajas = res_m2.boxes.xyxy.cpu().numpy()
        areas = (cajas[:, 2] - cajas[:, 0]) * (cajas[:, 3] - cajas[:, 1])
        orden = np.argsort(-areas)[:max_vehiculos]

        for idx in orden:
            vx1, vy1, vx2, vy2 = cajas[idx].tolist()
            cls_id = int(res_m2.boxes.cls[idx].item())
            conf_m2 = float(res_m2.boxes.conf[idx].item())
            nombre_clase = str(res_m2.names[cls_id]).lower().strip()
            categoria = MAPEO_CATEGORIAS.get(nombre_clase, "auto")

            vw, vh = vx2 - vx1, vy2 - vy1
            mx, my = vw * 0.08, vh * 0.08
            cx1 = int(max(0, vx1 - mx))
            cy1 = int(max(0, vy1 - my))
            cx2 = int(min(w, vx2 + mx))
            cy2 = int(min(h, vy2 + my))
            crop_v = img[cy1:cy2, cx1:cx2]
            cw = max(1, cx2 - cx1)
            ch = max(1, cy2 - cy1)

            with torch.inference_mode():
                ejes, llantas_det, pts_rel, lboxes_rel, kpts_ejes_rel = contar_ejes_en_recorte(
                    crop_v, categoria, imgsz=imgsz_m3
                )
                placa_leida, placa_norm, conf_ocr, m1_ok, bbox_p_crop = _detectar_placa_y_ocr_detalle(crop_v)
                pasajeros, mascaras_pas_rel = segmentar_pasajeros_en_recorte(crop_v, imgsz=imgsz_m4)

            bbox_placa_norm = None
            if m1_ok and bbox_p_crop is not None:
                px1, py1, px2, py2 = bbox_p_crop
                bbox_placa_norm = [
                    round((cx1 + px1) / w, 4),
                    round((cy1 + py1) / h, 4),
                    round((cx1 + px2) / w, 4),
                    round((cy1 + py2) / h, 4),
                ]

            llantas_puntos = [
                [round((cx1 + pt[0] * cw) / w, 4), round((cy1 + pt[1] * ch) / h, 4)]
                for pt in pts_rel
            ]
            keypoints_ejes = [
                {
                    "x": round((cx1 + k["x"] * cw) / w, 4),
                    "y": round((cy1 + k["y"] * ch) / h, 4),
                    "conf": k["conf"],
                }
                for k in kpts_ejes_rel
            ]
            llantas_boxes = [
                [
                    round((cx1 + lb[0] * cw) / w, 4),
                    round((cy1 + lb[1] * ch) / h, 4),
                    round((cx1 + lb[2] * cw) / w, 4),
                    round((cy1 + lb[3] * ch) / h, 4),
                ]
                for lb in lboxes_rel
            ]
            mascaras_pasajeros = [
                [[round((cx1 + p[0] * cw) / w, 4), round((cy1 + p[1] * ch) / h, 4)] for p in poly]
                for poly in mascaras_pas_rel
            ]

            mask_vehiculo = None
            if res_m2.masks is not None and idx < len(res_m2.masks.xyn):
                poly_v = res_m2.masks.xyn[idx]
                if len(poly_v) >= 3:
                    step_v = max(1, len(poly_v) // 32)
                    mask_vehiculo = [
                        [round(float(p[0]), 4), round(float(p[1]), 4)]
                        for p in poly_v[::step_v]
                    ]

            detecciones.append({
                "vehiculo_detectado": True,
                "categoria": categoria,
                "conf_vehiculo": round(conf_m2, 4),
                "bbox_vehiculo": [round(vx1 / w, 4), round(vy1 / h, 4), round(vx2 / w, 4), round(vy2 / h, 4)],
                "mask_vehiculo": mask_vehiculo,
                "placa_detectada": m1_ok,
                "placa_leida": placa_leida,
                "placa": placa_norm,
                "conf_ocr": conf_ocr,
                "bbox_placa": bbox_placa_norm,
                "ejes": ejes,
                "llantas": llantas_det,
                "llantas_puntos": llantas_puntos,
                "llantas_boxes": llantas_boxes,
                "keypoints_ejes": keypoints_ejes,
                "pasajeros": pasajeros,
                "mascaras_pasajeros": mascaras_pasajeros,
            })

    return detecciones


def dibujar_detecciones_multi(img: np.ndarray, detecciones: list[dict]) -> str:
    """Dibuja segmentación y caja de vehículos, pasajeros segmentados, ejes y placas."""
    canvas = img.copy()
    h, w = canvas.shape[:2]

    for det in detecciones:
        color_v = (118, 230, 0) if det.get("confirmado", True) else (255, 229, 0)

        # 1. Máscara de segmentación del vehículo (M2)
        mask_v = det.get("mask_vehiculo")
        if mask_v and len(mask_v) >= 3:
            pts_v = np.array([[int(p[0] * w), int(p[1] * h)] for p in mask_v], dtype=np.int32)
            overlay_v = canvas.copy()
            cv2.fillPoly(overlay_v, [pts_v], color_v)
            cv2.addWeighted(overlay_v, 0.20, canvas, 0.80, 0, canvas)
            cv2.polylines(canvas, [pts_v], True, color_v, 2)

        # 2. Máscaras de segmentación de pasajeros (magenta, sin recortes faciales)
        for poly in det.get("mascaras_pasajeros", []):
            if len(poly) >= 3:
                pts = np.array([[int(p[0] * w), int(p[1] * h)] for p in poly], dtype=np.int32)
                overlay = canvas.copy()
                cv2.fillPoly(overlay, [pts], (251, 64, 224))
                cv2.addWeighted(overlay, 0.35, canvas, 0.65, 0, canvas)
                cv2.polylines(canvas, [pts], True, (251, 64, 224), 2)

        # 3. Caja del vehículo (M2)
        bv = det.get("bbox_vehiculo")
        if bv:
            vx1, vy1, vx2, vy2 = int(bv[0] * w), int(bv[1] * h), int(bv[2] * w), int(bv[3] * h)
            cv2.rectangle(canvas, (vx1, vy1), (vx2, vy2), color_v, 2)
            pas = det.get("pasajeros", 0)
            pas_txt = f" | {pas} PAS" if pas > 0 else ""
            etiqueta_v = f"{det['categoria'].upper()} | {det['ejes']} EJES{pas_txt}"
            (tw, th), _ = cv2.getTextSize(etiqueta_v, cv2.FONT_HERSHEY_SIMPLEX, 0.58, 2)
            ty = max(th + 8, vy1)
            cv2.rectangle(canvas, (vx1, ty - th - 8), (vx1 + tw + 10, ty), color_v, -1)
            cv2.putText(canvas, etiqueta_v, (vx1 + 5, ty - 4), cv2.FONT_HERSHEY_SIMPLEX, 0.58, (0, 0, 0), 2)

        # 3. Cajas, keypoints y línea conectora de ejes (naranja/amarillo)
        for idx_l, lbox in enumerate(det.get("llantas_boxes", []), start=1):
            lx1, ly1, lx2, ly2 = int(lbox[0] * w), int(lbox[1] * h), int(lbox[2] * w), int(lbox[3] * h)
            cv2.rectangle(canvas, (lx1, ly1), (lx2, ly2), (0, 145, 255), 2)
            cv2.putText(canvas, f"E{idx_l}", (lx1, max(14, ly1 - 4)), cv2.FONT_HERSHEY_SIMPLEX, 0.45, (0, 145, 255), 2)

        kpts_ejes = det.get("keypoints_ejes", [])
        if len(kpts_ejes) >= 2:
            for a, b in zip(kpts_ejes, kpts_ejes[1:]):
                cv2.line(
                    canvas,
                    (int(a["x"] * w), int(a["y"] * h)),
                    (int(b["x"] * w), int(b["y"] * h)),
                    (0, 145, 255),
                    3,
                )
        for k in kpts_ejes:
            cx, cy = int(k["x"] * w), int(k["y"] * h)
            cv2.circle(canvas, (cx, cy), 6, (0, 234, 255), -1)
            cv2.circle(canvas, (cx, cy), 7, (0, 0, 0), 2)

        # 4. Caja de la placa + caracteres leídos (amarillo)
        bp = det.get("bbox_placa")
        if bp:
            px1, py1, px2, py2 = int(bp[0] * w), int(bp[1] * h), int(bp[2] * w), int(bp[3] * h)
            cv2.rectangle(canvas, (px1, py1), (px2, py2), (0, 214, 255), 3)
            placa = det.get("placa", "ILEGIBLE")
            conf_ocr = det.get("conf_ocr", 0.0)
            etiqueta_p = f"{placa} ({int(conf_ocr * 100)}%)" if placa and placa != "ILEGIBLE" else "ILEGIBLE"
            (pw, ph), _ = cv2.getTextSize(etiqueta_p, cv2.FONT_HERSHEY_SIMPLEX, 0.62, 2)
            py_box = min(h - 4, py2 + ph + 10)
            cv2.rectangle(canvas, (px1, py_box - ph - 8), (px1 + pw + 10, py_box), (0, 214, 255), -1)
            cv2.putText(canvas, etiqueta_p, (px1 + 5, py_box - 4), cv2.FONT_HERSHEY_SIMPLEX, 0.62, (0, 0, 0), 2)

    if w > 720:
        escala = 720.0 / w
        canvas = cv2.resize(canvas, (720, max(1, int(h * escala))), interpolation=cv2.INTER_AREA)

    ok, buf = cv2.imencode(".jpg", canvas, [int(cv2.IMWRITE_JPEG_QUALITY), 80])
    return base64.b64encode(buf.tobytes()).decode("ascii") if ok else ""


@app.get("/health")
def health():
    return {
        "status": "ok",
        "device": DEVICE,
        "modelos_cargados": {
            "m1_detector_onnx": det_placas is not None,
            "m1_ocr_onnx": ocr_placas is not None,
            "m2_vehiculos": M2_PATH.exists(),
            "m3_ejes": modelo_m3 is not None,
            "m4_pasajeros_seg": modelo_pasajeros is not None,
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

    h, w = img.shape[:2]
    detecciones = _procesar_vehiculos_crop_first(img, max_vehiculos=4, imgsz_m2=640, imgsz_m3=640, imgsz_m4=384)

    # Si M2 no detectó vehículos o la placa no salió dentro del recorte (ej. foto muy cercana)
    if not detecciones or not any(d["placa_detectada"] for d in detecciones):
        p_leida, p_norm, conf_ocr, m1_ok, bbox_p = _detectar_placa_y_ocr_detalle(img)
        if not detecciones:
            detecciones = [{
                "vehiculo_detectado": False,
                "categoria": "auto",
                "conf_vehiculo": 0.0,
                "bbox_vehiculo": None,
                "mask_vehiculo": None,
                "placa_detectada": m1_ok,
                "placa_leida": p_leida,
                "placa": p_norm,
                "conf_ocr": conf_ocr,
                "bbox_placa": [round(bbox_p[0] / w, 4), round(bbox_p[1] / h, 4), round(bbox_p[2] / w, 4), round(bbox_p[3] / h, 4)] if m1_ok and bbox_p else None,
                "ejes": 2,
                "llantas": 0,
                "llantas_puntos": [],
                "llantas_boxes": [],
                "keypoints_ejes": [],
                "pasajeros": 0,
                "mascaras_pasajeros": [],
            }]
        elif m1_ok:
            detecciones[0]["placa_detectada"] = True
            detecciones[0]["placa_leida"] = p_leida
            detecciones[0]["placa"] = p_norm
            detecciones[0]["conf_ocr"] = conf_ocr
            detecciones[0]["bbox_placa"] = [round(bbox_p[0] / w, 4), round(bbox_p[1] / h, 4), round(bbox_p[2] / w, 4), round(bbox_p[3] / h, 4)] if bbox_p else None

    principal = max(detecciones, key=lambda d: (1 if d["placa_detectada"] and d["placa"] != "ILEGIBLE" else 0, d["conf_vehiculo"]))
    imagen_anotada_b64 = dibujar_detecciones_multi(img, detecciones)
    latencia_ms = round((time.perf_counter() - t0) * 1000, 2)

    return {
        "placa_leida": principal["placa_leida"],
        "placa_normalizada": principal["placa"],
        "confianza_ocr": principal["conf_ocr"],
        "categoria_detectada": principal["categoria"],
        "ejes_detectados": principal["ejes"],
        "pasajeros": principal["pasajeros"],
        "latencia": latencia_ms,
        "bbox_vehiculo": principal["bbox_vehiculo"],
        "mask_vehiculo": principal.get("mask_vehiculo"),
        "bbox_placa": principal["bbox_placa"],
        "llantas_puntos": principal["llantas_puntos"],
        "llantas_boxes": principal["llantas_boxes"],
        "keypoints_ejes": principal.get("keypoints_ejes", []),
        "mascaras_pasajeros": principal["mascaras_pasajeros"],
        "detecciones": detecciones,
        "imagen_anotada_b64": imagen_anotada_b64,
        "detalles": {
            "device": DEVICE,
            "m1_placa_detectada": principal["placa_detectada"],
            "m2_confianza": principal["conf_vehiculo"],
            "m3_llantas_detectadas": principal["llantas"],
            "pasajeros_segmentados": principal["pasajeros"],
            "total_vehiculos": len(detecciones),
            "latencia_ms": latencia_ms,
        },
    }


def analizar_frame_vigilancia(img: np.ndarray, rastreador: RastreadorVigilancia) -> dict:
    """Detecta múltiples vehículos por recorte, estabiliza por IoU y segmenta pasajeros."""
    t0 = time.perf_counter()
    detecciones = _procesar_vehiculos_crop_first(img, max_vehiculos=2, imgsz_m2=384, imgsz_m3=320, imgsz_m4=416)
    detecciones_estables = rastreador.actualizar(detecciones)
    latencia_ms = round((time.perf_counter() - t0) * 1000, 1)

    principal = detecciones_estables[0] if detecciones_estables else None
    return {
        "detecciones": detecciones_estables,
        "total_vehiculos": len(detecciones_estables),
        "vehiculo_detectado": principal is not None,
        "categoria": principal["categoria"] if principal else "auto",
        "ejes": principal["ejes"] if principal else 2,
        "pasajeros": principal["pasajeros"] if principal else 0,
        "latencia_ms": latencia_ms,
        "device": DEVICE,
    }


@app.post("/vigilancia")
async def vigilancia_http(imagen: UploadFile = File(...)):
    contenido = await imagen.read()
    arr = np.frombuffer(contenido, np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise HTTPException(status_code=400, detail="Frame inválido.")
    return analizar_frame_vigilancia(img, rastreador_http)


@app.post("/inferir-video")
async def inferir_video(video: UploadFile = File(...)):
    """Procesa un clip de video (MP4/MOV/3GP), muestrea frames con rastreo IoU y devuelve el mejor resultado anotado."""
    t0 = time.perf_counter()
    contenido = await video.read()
    if not contenido:
        raise HTTPException(status_code=400, detail="El archivo de video está vacío.")

    suffix = Path(video.filename or "clip.mp4").suffix or ".mp4"
    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(contenido)
            tmp_path = tmp.name

        cap = cv2.VideoCapture(tmp_path)
        if not cap.isOpened():
            raise HTTPException(status_code=400, detail="No se pudo abrir el video enviado.")

        total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT)) or 0
        max_muestras = 16
        indices_objetivo: set[int] | None = None
        if total_frames > max_muestras:
            pasos = np.linspace(0, total_frames - 1, max_muestras, dtype=int)
            indices_objetivo = set(int(i) for i in pasos)

        rastreador_vid = RastreadorVigilancia()
        mejor_score = (-1, -1.0, -1)
        mejor_frame: np.ndarray | None = None
        mejor_detecciones: list[dict] = []
        mejor_principal: dict | None = None
        frames_analizados = 0

        idx_frame = 0
        while True:
            ret, frame = cap.read()
            if not ret:
                break
            if indices_objetivo is not None:
                if idx_frame not in indices_objetivo:
                    idx_frame += 1
                    continue
            elif idx_frame % 4 != 0:
                idx_frame += 1
                continue

            idx_frame += 1
            frames_analizados += 1
            detecciones = _procesar_vehiculos_crop_first(
                frame, max_vehiculos=4, imgsz_m2=640, imgsz_m3=480, imgsz_m4=416
            )
            estables = rastreador_vid.actualizar(detecciones)

            if mejor_frame is None:
                mejor_frame = frame

            if not estables:
                if frames_analizados >= max_muestras:
                    break
                continue

            cand = max(
                estables,
                key=lambda d: (
                    1 if d.get("confirmado") else 0,
                    1 if d.get("placa_detectada") and d.get("placa") != "ILEGIBLE" else 0,
                    d.get("conf_ocr", 0.0),
                    d.get("conf_vehiculo", 0.0),
                ),
            )
            score = (
                (2 if cand.get("confirmado") else (1 if cand.get("placa_detectada") and cand.get("placa") != "ILEGIBLE" else 0)),
                float(cand.get("conf_ocr", 0.0)) + float(cand.get("conf_vehiculo", 0.0)),
                int(cand.get("pasajeros", 0)),
            )
            if score > mejor_score:
                mejor_score = score
                mejor_frame = frame
                mejor_detecciones = estables
                mejor_principal = cand

            if frames_analizados >= max_muestras:
                break

        cap.release()

        if mejor_frame is None:
            raise HTTPException(status_code=400, detail="El video no contiene fotogramas legibles.")

        # Si M2 no detectó nada en todo el video, intentar M1 sobre el frame central
        if mejor_principal is None:
            h, w = mejor_frame.shape[:2]
            p_leida, p_norm, conf_ocr, m1_ok, bbox_p = _detectar_placa_y_ocr_detalle(mejor_frame)
            mejor_principal = {
                "vehiculo_detectado": False,
                "categoria": "auto",
                "conf_vehiculo": 0.0,
                "bbox_vehiculo": None,
                "mask_vehiculo": None,
                "placa_detectada": m1_ok,
                "placa_leida": p_leida,
                "placa": p_norm,
                "conf_ocr": conf_ocr,
                "bbox_placa": [round(bbox_p[0] / w, 4), round(bbox_p[1] / h, 4), round(bbox_p[2] / w, 4), round(bbox_p[3] / h, 4)] if m1_ok and bbox_p else None,
                "ejes": 2,
                "llantas": 0,
                "llantas_puntos": [],
                "llantas_boxes": [],
                "keypoints_ejes": [],
                "pasajeros": 0,
                "mascaras_pasajeros": [],
            }
            mejor_detecciones = [mejor_principal]

        imagen_anotada_b64 = dibujar_detecciones_multi(mejor_frame, mejor_detecciones)
        latencia_ms = round((time.perf_counter() - t0) * 1000, 2)

        return {
            "placa_leida": mejor_principal.get("placa_leida", "ILEGIBLE"),
            "placa_normalizada": mejor_principal.get("placa", "ILEGIBLE"),
            "confianza_ocr": mejor_principal.get("conf_ocr", 0.0),
            "categoria_detectada": mejor_principal.get("categoria", "auto"),
            "ejes_detectados": mejor_principal.get("ejes", 2),
            "pasajeros": mejor_principal.get("pasajeros", 0),
            "latencia": latencia_ms,
            "frames_analizados": frames_analizados,
            "bbox_vehiculo": mejor_principal.get("bbox_vehiculo"),
            "mask_vehiculo": mejor_principal.get("mask_vehiculo"),
            "bbox_placa": mejor_principal.get("bbox_placa"),
            "llantas_puntos": mejor_principal.get("llantas_puntos", []),
            "llantas_boxes": mejor_principal.get("llantas_boxes", []),
            "keypoints_ejes": mejor_principal.get("keypoints_ejes", []),
            "mascaras_pasajeros": mejor_principal.get("mascaras_pasajeros", []),
            "detecciones": mejor_detecciones,
            "imagen_anotada_b64": imagen_anotada_b64,
            "detalles": {
                "device": DEVICE,
                "frames_analizados": frames_analizados,
                "m1_placa_detectada": mejor_principal.get("placa_detectada", False),
                "m2_confianza": mejor_principal.get("conf_vehiculo", 0.0),
                "m3_llantas_detectadas": mejor_principal.get("llantas", 0),
                "pasajeros_segmentados": mejor_principal.get("pasajeros", 0),
                "latencia_ms": latencia_ms,
            },
        }
    finally:
        if tmp_path and os.path.exists(tmp_path):
            try:
                os.remove(tmp_path)
            except OSError:
                pass


@app.websocket("/ws/vigilancia")
async def ws_vigilancia(websocket: WebSocket):
    await websocket.accept()
    rastreador_ws = RastreadorVigilancia()
    try:
        while True:
            frame_bytes = await websocket.receive_bytes()
            arr = np.frombuffer(frame_bytes, np.uint8)
            img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
            if img is None:
                await websocket.send_json({"error": "frame_invalido"})
                continue
            resultado = analizar_frame_vigilancia(img, rastreador_ws)
            await websocket.send_json(resultado)
    except WebSocketDisconnect:
        pass
