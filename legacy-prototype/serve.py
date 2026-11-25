from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs
from email.parser import BytesParser
from email.policy import default
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation
from xml.etree import ElementTree as ET
from zipfile import ZipFile
from io import BytesIO, StringIO
import csv, json, os, re
from reportlab.lib.colors import HexColor

os.chdir(os.path.join(os.path.dirname(__file__), "public"))
MAX_UPLOAD = 25 * 1024 * 1024
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main", "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}

def col_index(ref):
    letters = re.match(r"[A-Z]+", ref or "")
    n = 0
    for c in letters.group(0) if letters else "": n = n * 26 + ord(c) - 64
    return n - 1

def excel_date(value, fmt):
    date = datetime(1899, 12, 30) + timedelta(days=float(value))
    fmt = re.sub(r"\[[^\]]+\]|\"[^\"]*\"|\\.", "", fmt.split(";")[0])
    tokens = {"yyyy": f"{date.year:04d}", "yy": f"{date.year % 100:02d}", "mmmm": date.strftime("%B"), "mmm": date.strftime("%b"), "mm": f"{date.month:02d}", "m": str(date.month), "dd": f"{date.day:02d}", "d": str(date.day)}
    return re.sub(r"yyyy|mmmm|mmm|yy|mm|dd|m|d", lambda m: tokens[m.group(0).lower()], fmt, flags=re.I)
def excel_number(value, fmt):
    """Render common Excel numeric masks without exposing binary float tails."""
    fmt = re.sub(r'"([^"]*)"', lambda m: m.group(1), fmt.split(";")[0]).replace("\\", "")
    if not fmt or fmt.lower() == "general": return str(value)
    try: number = Decimal(str(value))
    except InvalidOperation: return str(value)
    # Grouped date-like numeric masks such as 00.00.00 (25.07.17 in Excel).
    groups = re.fullmatch(r"(0{1,4})([./-])(0{1,4})\2(0{1,4})", fmt)
    if groups:
        widths = [len(groups.group(1)), len(groups.group(3)), len(groups.group(4))]
        digits = str(abs(int(number))).zfill(sum(widths)); pieces = []; end = len(digits)
        for width in reversed(widths): pieces.insert(0, digits[end-width:end]); end -= width
        return groups.group(2).join(pieces)
    # Standard decimal masks, optionally with thousands separators.
    mask = re.fullmatch(r"([#,]*0)(?:\.(0+))?", fmt)
    if mask:
        decimals = len(mask.group(2) or "")
        quant = Decimal(1).scaleb(-decimals)
        number = number.quantize(quant)
        return format(number, ("," if "," in mask.group(1) else "") + "." + str(decimals) + "f") if decimals else format(number, ",.0f" if "," in mask.group(1) else ".0f")
    if re.fullmatch(r"0+", fmt) and number == number.to_integral_value(): return str(int(number)).zfill(len(fmt))
    return str(value)
def display_mfg_date(value):
    """Use the displayed YY.MM.DD form when a workbook stores its date code as digits."""
    value = str(value or "").strip()
    match = re.fullmatch(r"(\d{2})(\d{2})(\d{2})", value)
    if match: return ".".join(match.groups())
    return value
def parse_xlsx(data):
    with ZipFile(BytesIO(data)) as z:
        names = set(z.namelist())
        shared = []
        if "xl/sharedStrings.xml" in names:
            root = ET.fromstring(z.read("xl/sharedStrings.xml"))
            for si in root.findall("m:si", NS): shared.append("".join(t.text or "" for t in si.findall(".//m:t", NS)))
        styles = []
        if "xl/styles.xml" in names:
            root = ET.fromstring(z.read("xl/styles.xml"))
            custom = {3:"#,##0",4:"#,##0.00",9:"0%",10:"0.00%",14:"m/d/yy",15:"d-mmm-yy",16:"d-mmm",17:"mmm-yy",20:"h:mm",21:"h:mm:ss",22:"m/d/yy h:mm"}
            custom.update({int(n.get("numFmtId")): n.get("formatCode", "") for n in root.findall("m:numFmts/m:numFmt", NS)})
            xfs = root.find("m:cellXfs", NS)
            if xfs is not None:
                for xf in list(xfs):
                    fmt = int(xf.get("numFmtId", "0")); styles.append(custom.get(fmt, ""))
        sheet_path = "xl/worksheets/sheet1.xml"
        wb = ET.fromstring(z.read("xl/workbook.xml"))
        first = wb.find("m:sheets/m:sheet", NS)
        if first is not None:
            rid = first.get("{" + NS["r"] + "}id")
            rels_path = "xl/_rels/workbook.xml.rels"
            if rels_path in names:
                rels = ET.fromstring(z.read(rels_path))
                rel = next((x for x in rels if x.get("Id") == rid), None)
                if rel is not None:
                    target = rel.get("Target", "worksheets/sheet1.xml").lstrip("/")
                    sheet_path = target if target.startswith("xl/") else "xl/" + target
        root = ET.fromstring(z.read(sheet_path))
        rows = []
        for row in root.findall("m:sheetData/m:row", NS):
            vals = []
            for cell in row.findall("m:c", NS):
                idx = col_index(cell.get("r")); vals.extend([""] * max(0, idx + 1 - len(vals)))
                typ = cell.get("t", "n"); v = cell.find("m:v", NS); value = v.text if v is not None and v.text is not None else ""
                if typ == "s" and value: value = shared[int(value)]
                elif typ == "inlineStr": value = "".join(t.text or "" for t in cell.findall(".//m:t", NS))
                elif typ == "b": value = "TRUE" if value == "1" else "FALSE"
                elif typ == "n" and value:
                    si = int(cell.get("s", "0")); fmt = styles[si] if si < len(styles) else ""
                    if fmt and re.search(r"(?:d{1,4}|m{1,4}|y{2,4})", fmt.lower().split(";")[0]):
                        try: value = excel_date(value, fmt)
                        except (ValueError, OverflowError): pass
                    else:
                        value = excel_number(value, fmt)
                vals[idx] = str(value)
            rows.append(vals)
        # Some source sheets encode MFG. DATE as a six-digit YYMMDD value with
        # General formatting. Preserve the date column as a readable date.
        if rows:
            header = [re.sub(r"\s+", "", str(x or "")).upper().rstrip(".") for x in rows[0]]
            date_idx = next((i for i, h in enumerate(header) if h in ("MFGDATE", "MFG.DATE")), None)
            if date_idx is not None:
                for vals in rows[1:]:
                    if date_idx < len(vals): vals[date_idx] = display_mfg_date(vals[date_idx])
        return rows

def parse_upload(content_type, body):
    msg = BytesParser(policy=default).parsebytes((f"Content-Type: {content_type}\r\nMIME-Version: 1.0\r\n\r\n").encode() + body)
    part = next((p for p in msg.iter_parts() if p.get_filename()), None)
    if part is None: raise ValueError("No workbook was received")
    filename = part.get_filename(); data = part.get_payload(decode=True) or b""
    if len(data) > MAX_UPLOAD: raise ValueError("Maximum upload size is 25 MB")
    if filename.lower().endswith(".csv"):
        text = data.decode("utf-8-sig")
        return filename, list(csv.reader(StringIO(text)))
    if filename.lower().endswith((".xlsx", ".xlsm")):
        return filename, parse_xlsx(data)
    raise ValueError("Upload .xlsx, .xlsm, or .csv")

def add_text(d, x, y, text, size=22, anchor="start", bold=False):
    from reportlab.graphics.shapes import String
    from reportlab.lib.colors import HexColor
    s = String(x, y, str(text or ""), fontName="Helvetica-Bold" if bold else "Helvetica", fontSize=size, fillColor=HexColor("#111111"), textAnchor=anchor)
    d.add(s)

def add_barcode(d, value, x, y, width, height):
    from reportlab.graphics.barcode import createBarcodeDrawing
    from reportlab.graphics.shapes import Rect
    drawing = createBarcodeDrawing("Code128", value=str(value), barHeight=height, humanReadable=False, quiet=True)
    obj = drawing.contents[0]; obj._calculate()
    patterns = obj.decomposed; scale = width / drawing.width; pos = x + obj.lquiet * scale
    unit = obj.barWidth * scale
    for c in patterns:
        n = ord(c) - (ord("a") if c.islower() else ord("A")) + 1
        w = n * unit
        if c.isupper(): d.add(Rect(pos, y, w, height, strokeColor=None, fillColor=HexColor("#111111")))
        pos += w

def add_qr(d, value, x, y, size):
    from reportlab.graphics.barcode.qr import QrCodeWidget
    from reportlab.graphics.shapes import Rect
    q = QrCodeWidget(str(value)); q.qr.make(); modules = q.qr.modules; count = q.qr.getModuleCount(); border = q.barBorder
    box = size / (count + border * 2)
    for r, row in enumerate(modules):
        c = 0
        while c < count:
            if not row[c]: c += 1; continue
            start = c
            while c < count and row[c]: c += 1
            d.add(Rect(x + (start + border) * box, y + size - (r + border + 1) * box, (c - start) * box, box, strokeColor=None, fillColor=HexColor("#111111")))
def make_label_svg(data):
    from reportlab.graphics.shapes import Drawing, Rect, Line
    from reportlab.lib.colors import HexColor
    from reportlab.graphics.barcode.qr import QrCodeWidget
    from reportlab.graphics import renderSVG
    W, H = 1400, 950
    d = Drawing(W, H)
    d.add(Rect(1, 1, W-2, H-2, strokeColor=HexColor("#111111"), fillColor=HexColor("#ffffff"), strokeWidth=2))
    # Row and column rules match the supplied label proportions.
    for y in (680, 430, 230): d.add(Line(1, y, W-1, y, strokeColor=HexColor("#111111"), strokeWidth=2))
    d.add(Line(1060, 680, 1060, H-1, strokeColor=HexColor("#111111"), strokeWidth=2))
    for y1, y2 in ((430,680),(230,430)):
        d.add(Line(470, y1, 470, y2, strokeColor=HexColor("#111111"), strokeWidth=2)); d.add(Line(1010, y1, 1010, y2, strokeColor=HexColor("#111111"), strokeWidth=2))
    d.add(Line(910, 1, 910, 230, strokeColor=HexColor("#111111"), strokeWidth=2))
    # Part number and its exact Excel barcode value.
    add_text(d, 10, 895, "PART NO.", 29); add_text(d, 10, 865, "(P)", 26)
    add_text(d, 535, 842, data.get("partNumber", ""), 72, "middle")
    add_barcode(d, data.get("partNumberCode", ""), 170, 720, 840, 68)
    add_text(d, 590, 696, "   ".join(data.get("partNumberCode", "")), 14, "middle")
    add_qr(d, data.get("qrCode", ""), 1125, 718, 185)
    # Quantity, description, carton weight and model/component fields.
    add_text(d, 10, 648, "QUANTITY", 27); add_text(d, 10, 600, "(Q)", 25); add_text(d, 305, 560, data.get("quantity", ""), 65, "middle")
    add_barcode(d, data.get("quantityCode", ""), 85, 455, 300, 38); add_text(d, 235, 438, "   ".join(data.get("quantityCode", "")), 11, "middle")
    add_text(d, 480, 648, "PARTS DESCRIPTION", 24); add_text(d, 737, 555, data.get("partName", ""), 27, "middle")
    d.add(Line(470, 542, 1010, 542, strokeColor=HexColor("#111111"), strokeWidth=2)); add_text(d, 480, 505, "CARTON WEIGHT (Kg)", 23); add_text(d, 737, 455, data.get("cartonWeight", ""), 30, "middle")
    add_text(d, 1025, 648, "MODEL NAME", 24); add_text(d, 1205, 575, data.get("modelName", ""), 29, "middle")
    d.add(Line(1010, 542, 1399, 542, strokeColor=HexColor("#111111"), strokeWidth=2)); add_text(d, 1025, 505, "COMPONENT S/N", 23)
    # Supplier number and its exact barcode value.
    add_text(d, 10, 398, "SUPPLIER NUMBER", 24); add_text(d, 10, 365, "(V)", 24); add_text(d, 395, 350, data.get("supplierNumber", ""), 32, "end")
    add_barcode(d, data.get("supplierCode", ""), 70, 255, 340, 52); add_text(d, 240, 238, "   ".join(data.get("supplierCode", "")), 11, "middle")
    add_text(d, 480, 398, "MFG DAT", 24); add_text(d, 740, 344, data.get("mfgDate", ""), 34, "middle")
    add_text(d, 1025, 398, "SUPPLIER PN", 24); add_text(d, 1025, 360, "(2P)", 23); add_text(d, 1205, 300, data.get("supplierPn", ""), 28, "middle")
    # Serial number and its exact serial barcode value.
    add_text(d, 10, 200, "SERIAL NO.", 25); add_text(d, 10, 150, "(S)", 24); add_text(d, 455, 148, data.get("serialNo", ""), 30, "middle")
    add_barcode(d, data.get("serialCode", ""), 60, 32, 660, 75); add_text(d, 390, 14, "   ".join(data.get("serialCode", "")), 13, "middle")
    add_text(d, 925, 200, "MADE IN PHILIPPINE", 24); add_text(d, 1155, 100, "PHILIPPINES OGAMI", 24, "middle"); add_text(d, 1155, 66, "CORPORATION", 24, "middle")
    return renderSVG.drawToString(d).encode("utf-8")

class Handler(SimpleHTTPRequestHandler):
    def end_headers(self): self.send_header("Cache-Control", "no-store"); super().end_headers()
    def do_GET(self):
        parsed = urlparse(self.path); params = parse_qs(parsed.query)
        if parsed.path == "/api/label.svg":
            try:
                obj = json.loads(params.get("data", [""])[0]); body = make_label_svg(obj)
                self.send_response(200); self.send_header("Content-Type", "image/svg+xml; charset=utf-8"); self.send_header("Content-Length", str(len(body))); self.send_header("Cache-Control", "public, max-age=3600"); self.end_headers(); self.wfile.write(body)
            except Exception as exc: self.send_error(422, "Could not create label from the supplied row")
            return
        super().do_GET()
    def do_POST(self):
        if urlparse(self.path).path != "/api/parse": self.send_error(404); return
        length = int(self.headers.get("Content-Length", "0"))
        if length > MAX_UPLOAD: self.send_error(413, "Maximum upload size is 25 MB"); return
        try:
            filename, rows = parse_upload(self.headers.get("Content-Type", ""), self.rfile.read(length))
            if len(rows) < 2: raise ValueError("The workbook needs a header row and at least one data row")
            result = {"filename": filename, "headers": [str(x).strip() for x in rows[0]], "rows": rows[1:]}
            result["rows"] = [r for r in result["rows"] if any(str(x or "").strip() for x in r)]
            body = json.dumps(result, ensure_ascii=False).encode("utf-8")
            self.send_response(200); self.send_header("Content-Type", "application/json; charset=utf-8"); self.send_header("Content-Length", str(len(body))); self.end_headers(); self.wfile.write(body)
        except Exception as exc:
            body = json.dumps({"error": str(exc)}).encode("utf-8"); self.send_response(400); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(body))); self.end_headers(); self.wfile.write(body)

if __name__ == "__main__":
    print("Ogami Label Studio running at http://localhost:8000")
    ThreadingHTTPServer(("127.0.0.1", 8000), Handler).serve_forever()
