"""Motor de curvas paramétricas e guilloché vetorial contemporâneo.

Gera padrões numismáticos de segurança de alta precisão vetorial no canvas
do ReportLab, inspirados no design moderno de passaportes e cédulas contemporâneas:
fitas de ondas harmônicas contínuas (wavefields), linhas micrométricas perimétricas
e selos geométricos paramétricos.
"""

import math
from typing import Sequence, Tuple, Union
from reportlab.graphics.shapes import Drawing, Group, Path, Circle
from reportlab.lib import colors
from reportlab.pdfgen import canvas


def resolve_color(color_spec: Union[str, colors.Color, Sequence[float]]) -> colors.Color:
    """Resolve uma especificação de cor (string hexadecimal, tupla ou objeto Color do ReportLab)."""
    if isinstance(color_spec, colors.Color):
        return color_spec
    if isinstance(color_spec, str):
        color_str = color_spec.strip()
        if color_str.startswith("#"):
            return colors.HexColor(color_str)
        # Tenta mapear nomes comuns
        if hasattr(colors, color_str):
            return getattr(colors, color_str)
        return colors.HexColor(color_str)
    if isinstance(color_spec, (tuple, list)):
        if len(color_spec) == 3:
            return colors.Color(color_spec[0], color_spec[1], color_spec[2])
        elif len(color_spec) == 4:
            return colors.Color(color_spec[0], color_spec[1], color_spec[2], alpha=color_spec[3])
    return colors.HexColor("#0E7490")


def interpolate_color(c1: colors.Color, c2: colors.Color, factor: float) -> colors.Color:
    """Interpola suavemente entre duas cores (0.0 <= factor <= 1.0)."""
    factor = max(0.0, min(1.0, factor))
    r = c1.red + (c2.red - c1.red) * factor
    g = c1.green + (c2.green - c1.green) * factor
    b = c1.blue + (c2.blue - c1.blue) * factor
    alpha = getattr(c1, "alpha", 1.0) + (getattr(c2, "alpha", 1.0) - getattr(c1, "alpha", 1.0)) * factor
    return colors.Color(r, g, b, alpha=alpha)


def draw_modern_wave_ribbon(
    c: canvas.Canvas,
    x_start: float,
    y_start: float,
    x_end: float,
    y_end: float,
    num_waves: int = 7,
    amplitude: float = 14.0,
    frequency: float = 2.5,
    primary_color: Union[str, colors.Color] = "#0E7490",
    secondary_color: Union[str, colors.Color] = "#EA580C",
    line_width: float = 0.5,
    orientation: str = "vertical",
    steps: int = 140,
) -> None:
    """
    Desenha uma fita de ondas harmônicas contínuas (wavefield ribbon) de alta precisão
    vetorial, com variação sinusoidal composta e gradiente de traço.
    """
    col_primary = resolve_color(primary_color)
    col_secondary = resolve_color(secondary_color)
    c.saveState()

    length = math.hypot(x_end - x_start, y_end - y_start)
    if length <= 0:
        c.restoreState()
        return

    for i in range(num_waves):
        t_factor = i / max(1, num_waves - 1)
        line_color = interpolate_color(col_primary, col_secondary, t_factor)
        c.setStrokeColor(line_color)
        
        # Variação sutil de espessura (traço micrométrico numismático)
        w = line_width * (0.8 + 0.4 * (1.0 - t_factor))
        c.setLineWidth(w)

        path = c.beginPath()
        phase_shift = (i * 0.28)
        lateral_offset = (i - num_waves / 2.0) * 2.8

        for s in range(steps + 1):
            fraction = s / steps
            # Posição linear ao longo do eixo principal
            cur_x = x_start + (x_end - x_start) * fraction
            cur_y = y_start + (y_end - y_start) * fraction

            # Modulação sinusoidal composta (frequência base + harmônico secundário)
            angle = fraction * 2.0 * math.pi * frequency + phase_shift
            displacement = (
                amplitude * math.sin(angle)
                + (amplitude * 0.35) * math.cos(angle * 2.1)
                + lateral_offset
            )

            # Aplica o deslocamento perpendicularmente ao segmento
            if orientation == "vertical":
                px = cur_x + displacement
                py = cur_y
            else:
                px = cur_x
                py = cur_y + displacement

            if s == 0:
                path.moveTo(px, py)
            else:
                path.lineTo(px, py)

        c.drawPath(path, stroke=1, fill=0)

    c.restoreState()


def draw_security_seal(
    c: canvas.Canvas,
    cx: float,
    cy: float,
    radius: float = 26.0,
    primary_color: Union[str, colors.Color] = "#0E7490",
    secondary_color: Union[str, colors.Color] = "#EA580C",
    petals: int = 12,
    steps: int = 180,
) -> None:
    """
    Desenha um selo numismático contemporâneo baseado em curvas paramétricas de roseta
    e anéis de micro-segurança.
    """
    col_primary = resolve_color(primary_color)
    col_secondary = resolve_color(secondary_color)
    c.saveState()

    # Anel externo pontilhado fino
    c.setStrokeColor(col_secondary)
    c.setLineWidth(0.75)
    c.setDash([2, 2], 0)
    c.circle(cx, cy, radius, stroke=1, fill=0)

    # Anel intermediário contínuo
    c.setStrokeColor(col_primary)
    c.setLineWidth(0.4)
    c.setDash([], 0)
    c.circle(cx, cy, radius * 0.82, stroke=1, fill=0)

    # Roseta paramétrica harmônica (hipotrocoide com N pétalas)
    path = c.beginPath()
    amp = radius * 0.22
    base_r = radius * 0.58

    for s in range(steps + 1):
        theta = (s / steps) * 2.0 * math.pi
        r = base_r + amp * math.cos(petals * theta)
        px = cx + r * math.cos(theta)
        py = cy + r * math.sin(theta)
        if s == 0:
            path.moveTo(px, py)
        else:
            path.lineTo(px, py)

    c.setStrokeColor(col_secondary)
    c.setLineWidth(0.5)
    c.drawPath(path, stroke=1, fill=0)

    # Ponto central e micro-núcleo
    c.setFillColor(col_primary)
    c.circle(cx, cy, radius * 0.16, stroke=0, fill=1)
    c.setFillColor(col_secondary)
    c.circle(cx, cy, radius * 0.07, stroke=0, fill=1)

    c.restoreState()


def draw_continuous_l_ribbon(
    c: canvas.Canvas,
    x_base: float = 19.0,
    y_top: float = 595.27,
    y_corner: float = 19.0,
    x_right: float = 841.89,
    radius: float = 20.0,
    num_waves: int = 16,
    amplitude: float = 7.0,
    frequency: float = 5.0,
    spacing: float = 1.0,
    phase_step: float = 0.22,
    primary_color: Union[str, colors.Color] = "#0E7490",
    secondary_color: Union[str, colors.Color] = "#EA580C",
    line_width: float = 0.28,
    num_samples: int = 800,
) -> None:
    """
    Desenha uma fita contínua em L de alta densidade numismática, composta por um feixe de
    linhas harmônicas micrométricas que fluem com defasagem angular progressiva suave,
    expandindo-se para fora em direção à sangria e ao canto, e preservando o
    respiro dos textos do certificado.
    """
    col_primary = resolve_color(primary_color)
    col_secondary = resolve_color(secondary_color)
    c.saveState()

    len_v = y_top - (y_corner + radius)
    len_arc = (math.pi / 2.0) * radius
    len_h = x_right - (x_base + radius)
    total_len = len_v + len_arc + len_h

    if total_len <= 0:
        c.restoreState()
        return

    for w_idx in range(num_waves):
        t_factor = w_idx / max(1, num_waves - 1)
        line_col = interpolate_color(col_primary, col_secondary, t_factor)
        c.setStrokeColor(line_col)
        c.setLineWidth(line_width + 0.12 * (1.0 - t_factor))

        lat_offset = (w_idx - (num_waves - 1) / 2.0) * spacing
        phase = w_idx * phase_step

        path = c.beginPath()

        for s in range(num_samples + 1):
            d = (s / num_samples) * total_len
            frac = s / num_samples

            if d <= len_v:
                t = d / len_v
                px_base = x_base
                py_base = y_top - t * len_v
                nx, ny = 1.0, 0.0
            elif d <= len_v + len_arc:
                arc_d = d - len_v
                theta = (arc_d / len_arc) * (math.pi / 2.0)
                ang = math.pi + theta
                cx = x_base + radius
                cy = y_corner + radius
                px_base = cx + radius * math.cos(ang)
                py_base = cy + radius * math.sin(ang)
                nx = -math.cos(ang)
                ny = -math.sin(ang)
            else:
                h_d = d - (len_v + len_arc)
                t = h_d / len_h
                px_base = (x_base + radius) + t * len_h
                py_base = y_corner
                nx, ny = 0.0, 1.0

            angle = frac * 2.0 * math.pi * frequency + phase
            disp = (
                lat_offset
                + amplitude * math.sin(angle)
                + (amplitude * 0.35) * math.cos(angle * 2.1)
            )

            x_pt = px_base + nx * disp
            y_pt = py_base + ny * disp

            if s == 0:
                path.moveTo(x_pt, y_pt)
            else:
                path.lineTo(x_pt, y_pt)

        c.drawPath(path, stroke=1, fill=0)

    c.restoreState()


def draw_guilloche_frame(
    c: canvas.Canvas,
    width: float = 841.89,
    height: float = 595.27,
    margin: float = 24.0,
    primary_color: Union[str, colors.Color] = "#0E7490",
    secondary_color: Union[str, colors.Color] = "#EA580C",
    include_seal: bool = False,
    include_microtext: bool = False,
) -> None:
    """
    Renderiza a moldura de segurança completa do anverso:
    - Linhas guia perimétricas de alta precisão numismática.
    - Fita contínua em L de ondas harmônicas micrométricas (16 linhas com defasagem angular progressiva suave)
      com expansão externa e sangria total encostada nos limites da página.
    - Suporte opcional ao selo numismático contemporâneo.
    """
    col_primary = resolve_color(primary_color)
    col_secondary = resolve_color(secondary_color)
    c.saveState()

    # Linhas guia perimétricas externas e internas
    c.setStrokeColor(col_primary)
    c.setLineWidth(0.65)
    c.rect(margin + 2, margin + 2, width - (margin + 2) * 2, height - (margin + 2) * 2, stroke=1, fill=0)

    c.setStrokeColor(col_secondary)
    c.setLineWidth(0.35)
    c.rect(margin + 6, margin + 6, width - (margin + 6) * 2, height - (margin + 6) * 2, stroke=1, fill=0)

    # Microtexto anti-cópia opcional (desativado por padrão conforme diretriz estética)
    if include_microtext:
        c.setFont("Helvetica", 3.0)
        c.setFillColor(colors.HexColor("#64748B"))
        micro_txt = "FUTURO FÁCIL · CAPACITAÇÃO PROFISSIONAL SOB MEDIDA · CERTIFICADO OFICIAL · VALIDADE NACIONAL LEI 9.394/96 E DEC 5.154/2004 · AUTENTICIDADE DIGITAL GARANTIDA · "
        c.drawString(margin + 12, height - margin - 12, (micro_txt * 4)[:220])
        c.drawString(margin + 12, margin + 10, (micro_txt * 4)[:220])

    # Fita de ondas harmônicas contínua em L (16 linhas micrométricas em defasagem suave)
    draw_continuous_l_ribbon(
        c,
        x_base=19.0,
        y_top=height,
        y_corner=19.0,
        x_right=width,
        radius=20.0,
        num_waves=16,
        amplitude=7.0,
        frequency=5.0,
        spacing=1.0,
        phase_step=0.22,
        primary_color=col_primary,
        secondary_color=col_secondary,
    )

    # Selo numismático opcional (se solicitado explicitamente)
    if include_seal:
        draw_security_seal(
            c,
            cx=width - margin - 42,
            cy=margin + 42,
            radius=26.0,
            primary_color=col_primary,
            secondary_color=col_secondary,
            petals=12,
        )

    c.restoreState()

