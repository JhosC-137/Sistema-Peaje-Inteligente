# Notebooks de Entrenamiento (`/entrenamiento`)

Coloca aquí los notebooks de Google Colab / Jupyter (archivos `.ipynb`) utilizados para entrenar los modelos en la nube:

- `m2_vehiculos_seg.ipynb` — Entrenamiento de segmentación de instancias (`yolo11-seg`) para clasificación vehicular (`moto`, `auto`, `minibus`, `camion`, `bus`).
- `m3_ejes_pose.ipynb` — Entrenamiento de keypoints (`yolo11-pose`) para detección de ruedas y conteo de ejes.
- `m4_pasajeros_seg.ipynb` — Entrenamiento de segmentación de pasajeros dentro de vehículos.

> **Importante:** Antes de hacer `git push`, verifica que ninguno de los archivos `.ipynb` contenga tu `ROBOFLOW_API_KEY` u otros tokens guardados en las celdas o salidas.
