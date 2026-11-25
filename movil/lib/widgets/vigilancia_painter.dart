import 'package:flutter/material.dart';

class VigilanciaPainter extends CustomPainter {
  final Map<String, dynamic>? deteccion;

  const VigilanciaPainter(this.deteccion);

  @override
  void paint(Canvas canvas, Size size) {
    final data = deteccion;
    if (data == null || size.width <= 0 || size.height <= 0) return;

    final List<dynamic> lista = data['detecciones'] is List
        ? data['detecciones'] as List<dynamic>
        : [data];

    for (final item in lista) {
      if (item is! Map) continue;
      _dibujarDeteccion(canvas, size, item);
    }
  }

  void _dibujarDeteccion(Canvas canvas, Size size, Map det) {
    final confirmado = det['confirmado'] == true;
    final colorVehiculo = confirmado
        ? const Color(0xFF00E676)
        : const Color(0xFF00E5FF);

    // 1. Máscara de segmentación del vehículo (M2)
    final maskVehiculo = det['mask_vehiculo'];
    if (maskVehiculo is List && maskVehiculo.length >= 3) {
      final pathV = Path();
      for (int i = 0; i < maskVehiculo.length; i++) {
        final pt = maskVehiculo[i];
        if (pt is List && pt.length >= 2) {
          final px = (pt[0] as num).toDouble() * size.width;
          final py = (pt[1] as num).toDouble() * size.height;
          if (i == 0) {
            pathV.moveTo(px, py);
          } else {
            pathV.lineTo(px, py);
          }
        }
      }
      pathV.close();
      canvas.drawPath(
        pathV,
        Paint()
          ..color = colorVehiculo.withValues(alpha: 0.18)
          ..style = PaintingStyle.fill,
      );
      canvas.drawPath(
        pathV,
        Paint()
          ..color = colorVehiculo
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.6,
      );
    }

    // 2. Máscaras de segmentación de pasajeros (magenta, sin rostro)
    final mascarasPasajeros = det['mascaras_pasajeros'];
    if (mascarasPasajeros is List) {
      final fillPasajero = Paint()
        ..color = const Color(0xFFE040FB).withValues(alpha: 0.34)
        ..style = PaintingStyle.fill;
      final strokePasajero = Paint()
        ..color = const Color(0xFFE040FB)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.0;

      for (final poly in mascarasPasajeros) {
        if (poly is List && poly.length >= 3) {
          final path = Path();
          for (int i = 0; i < poly.length; i++) {
            final pt = poly[i];
            if (pt is List && pt.length >= 2) {
              final px = (pt[0] as num).toDouble() * size.width;
              final py = (pt[1] as num).toDouble() * size.height;
              if (i == 0) {
                path.moveTo(px, py);
              } else {
                path.lineTo(px, py);
              }
            }
          }
          path.close();
          canvas.drawPath(path, fillPasajero);
          canvas.drawPath(path, strokePasajero);
        }
      }
    }

    // 3. Caja del vehículo (M2)
    final bv = det['bbox_vehiculo'];
    if (bv is List && bv.length == 4) {

      final rect = Rect.fromLTRB(
        (bv[0] as num).toDouble() * size.width,
        (bv[1] as num).toDouble() * size.height,
        (bv[2] as num).toDouble() * size.width,
        (bv[3] as num).toDouble() * size.height,
      );
      canvas.drawRect(
        rect,
        Paint()
          ..color = colorVehiculo
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.5,
      );

      final tid = det['track_id'];
      final prefijo = tid != null ? '#$tid • ' : '';
      final cat = (det['categoria'] ?? '').toString().toUpperCase();
      final ejes = det['ejes'] ?? 2;
      final pas = (det['pasajeros'] as num?)?.toInt() ?? 0;
      final pasTxt = pas > 0 ? ' • $pas PAS' : '';
      _dibujarEtiqueta(
        canvas,
        size,
        Offset(rect.left, rect.top - 22),
        '$prefijo$cat • $ejes EJES$pasTxt',
        colorVehiculo,
      );
    }

    // 3. Cajas, línea conectora y keypoints de ejes/llantas (M3)
    final llantasBoxes = det['llantas_boxes'];
    if (llantasBoxes is List) {
      final paintLlantaBox = Paint()
        ..color = const Color(0xFFFF9100)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.0;
      for (int i = 0; i < llantasBoxes.length; i++) {
        final lb = llantasBoxes[i];
        if (lb is List && lb.length == 4) {
          final rectL = Rect.fromLTRB(
            (lb[0] as num).toDouble() * size.width,
            (lb[1] as num).toDouble() * size.height,
            (lb[2] as num).toDouble() * size.width,
            (lb[3] as num).toDouble() * size.height,
          );
          canvas.drawRect(rectL, paintLlantaBox);
          _dibujarEtiqueta(
            canvas,
            size,
            Offset(rectL.left, rectL.top - 16),
            'E${i + 1}',
            const Color(0xFFFF9100),
          );
        }
      }
    }

    final kpts = det['keypoints_ejes'];
    if (kpts is List && kpts.isNotEmpty) {
      final linePaint = Paint()
        ..color = const Color(0xFFFF9100)
        ..strokeWidth = 3.0
        ..style = PaintingStyle.stroke;
      final dotFill = Paint()..color = const Color(0xFFFFEA00);
      final dotBorder = Paint()
        ..color = Colors.black
        ..strokeWidth = 1.5
        ..style = PaintingStyle.stroke;

      final offsets = kpts
          .whereType<Map>()
          .map<Offset>(
            (k) => Offset(
              (k['x'] as num).toDouble() * size.width,
              (k['y'] as num).toDouble() * size.height,
            ),
          )
          .toList();

      for (int i = 0; i < offsets.length - 1; i++) {
        canvas.drawLine(offsets[i], offsets[i + 1], linePaint);
      }
      for (final off in offsets) {
        canvas.drawCircle(off, 6.0, dotFill);
        canvas.drawCircle(off, 6.0, dotBorder);
      }
    } else {
      final llantas = det['llantas_puntos'];
      if (llantas is List) {
        final fillLlanta = Paint()..color = const Color(0xFFFFEA00);
        final bordeLlanta = Paint()
          ..color = Colors.black
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5;
        for (final pt in llantas) {
          if (pt is List && pt.length >= 2) {
            final center = Offset(
              (pt[0] as num).toDouble() * size.width,
              (pt[1] as num).toDouble() * size.height,
            );
            canvas.drawCircle(center, 5.5, fillLlanta);
            canvas.drawCircle(center, 5.5, bordeLlanta);
          }
        }
      }
    }

    // 4. Caja de la placa (M1) y texto estabilizado
    final bp = det['bbox_placa'];
    if (bp is List && bp.length == 4) {
      final rectPlaca = Rect.fromLTRB(
        (bp[0] as num).toDouble() * size.width,
        (bp[1] as num).toDouble() * size.height,
        (bp[2] as num).toDouble() * size.width,
        (bp[3] as num).toDouble() * size.height,
      );
      canvas.drawRect(
        rectPlaca,
        Paint()
          ..color = const Color(0xFFFFD600)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.5,
      );

      final placa = (det['placa'] ?? '').toString();
      if (placa.isNotEmpty) {
        _dibujarEtiqueta(
          canvas,
          size,
          Offset(rectPlaca.left, rectPlaca.bottom + 4),
          placa,
          const Color(0xFFFFD600),
        );
      }
    }
  }

  void _dibujarEtiqueta(
    Canvas canvas,
    Size size,
    Offset pos,
    String texto,
    Color color,
  ) {
    final tp = TextPainter(
      text: TextSpan(
        text: ' $texto ',
        style: const TextStyle(
          color: Colors.black,
          fontWeight: FontWeight.bold,
          fontSize: 11.5,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();

    final dx = pos.dx.clamp(0.0, (size.width - tp.width).clamp(0.0, size.width));
    final dy = pos.dy.clamp(
      0.0,
      (size.height - tp.height - 4).clamp(0.0, size.height),
    );
    canvas.drawRect(
      Rect.fromLTWH(dx, dy, tp.width, tp.height + 4),
      Paint()..color = color,
    );
    tp.paint(canvas, Offset(dx, dy + 2));
  }

  @override
  bool shouldRepaint(covariant VigilanciaPainter oldDelegate) =>
      oldDelegate.deteccion != deteccion;
}
