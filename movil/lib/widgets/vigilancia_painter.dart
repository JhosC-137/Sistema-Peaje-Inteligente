import 'package:flutter/material.dart';

class VigilanciaPainter extends CustomPainter {
  final Map<String, dynamic>? deteccion;

  const VigilanciaPainter(this.deteccion);

  @override
  void paint(Canvas canvas, Size size) {
    final data = deteccion;
    if (data == null || size.width <= 0 || size.height <= 0) return;

    // 1. Máscara de segmentación del vehículo (M2) semitransparente
    final mask = data['mask_vehiculo'];
    if (mask is List && mask.length >= 3) {
      final path = Path();
      for (int i = 0; i < mask.length; i++) {
        final pt = mask[i];
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
      canvas.drawPath(
        path,
        Paint()
          ..color = const Color(0xFF00E676).withValues(alpha: 0.20)
          ..style = PaintingStyle.fill,
      );
      canvas.drawPath(
        path,
        Paint()
          ..color = const Color(0xFF00E676)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5,
      );
    }

    // 2. Caja del vehículo (M2) en verde cian
    final bv = data['bbox_vehiculo'];
    if (bv is List && bv.length == 4) {
      final rect = Rect.fromLTRB(
        (bv[0] as num).toDouble() * size.width,
        (bv[1] as num).toDouble() * size.height,
        (bv[2] as num).toDouble() * size.width,
        (bv[3] as num).toDouble() * size.height,
      );
      final paintVehiculo = Paint()
        ..color = const Color(0xFF00E676)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.5;
      canvas.drawRect(rect, paintVehiculo);

      final cat = (data['categoria'] ?? '').toString().toUpperCase();
      final ejes = data['ejes'] ?? 2;
      _dibujarEtiqueta(
        canvas,
        size,
        Offset(rect.left, rect.top - 22),
        '$cat • $ejes EJES',
        const Color(0xFF00E676),
      );
    }

    // 3. Keypoints de llantas detectadas (M3)
    final llantas = data['llantas_puntos'];
    if (llantas is List) {
      final fillLlanta = Paint()..color = const Color(0xFFFF9100);
      final bordeLlanta = Paint()
        ..color = Colors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5;
      for (final pt in llantas) {
        if (pt is List && pt.length >= 2) {
          final center = Offset(
            (pt[0] as num).toDouble() * size.width,
            (pt[1] as num).toDouble() * size.height,
          );
          canvas.drawCircle(center, 6, fillLlanta);
          canvas.drawCircle(center, 7, bordeLlanta);
        }
      }
    }

    // 4. Caja de la placa (M1) y caracteres reconocidos en ámbar/amarillo
    final bp = data['bbox_placa'];
    if (bp is List && bp.length == 4) {
      final rectPlaca = Rect.fromLTRB(
        (bp[0] as num).toDouble() * size.width,
        (bp[1] as num).toDouble() * size.height,
        (bp[2] as num).toDouble() * size.width,
        (bp[3] as num).toDouble() * size.height,
      );
      final paintPlaca = Paint()
        ..color = const Color(0xFFFFD600)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.5;
      canvas.drawRect(rectPlaca, paintPlaca);

      final placa = (data['placa'] ?? '').toString();
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
          fontSize: 12,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();

    final dx = pos.dx.clamp(
      0.0,
      (size.width - tp.width).clamp(0.0, size.width),
    );
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
