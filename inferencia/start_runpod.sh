#!/usr/bin/env bash
set -e

echo "=== Instalando dependencias de inferencia ==="
python3 -m pip install --upgrade pip
python3 -m pip install -r requirements.txt

echo "=== Diagnóstico de entorno (GPU/CUDA/Ultralytics) ==="
python3 -c "import sys, torch, ultralytics, onnxruntime; print('Python:', sys.version.split()[0]); print('PyTorch:', torch.__version__, '| CUDA disponible:', torch.cuda.is_available(), '| GPU:', torch.cuda.get_device_name(0) if torch.cuda.is_available() else 'CPU'); print('Ultralytics:', ultralytics.__version__); print('ONNXRuntime:', onnxruntime.__version__)"

echo "=== Iniciando servidor FastAPI en puerto 8000 ==="
exec uvicorn main:app --host 0.0.0.0 --port 8000
