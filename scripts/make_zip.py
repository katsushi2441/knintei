#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""配布ZIP（kappstore 同梱物）を作る。

  /usr/bin/python3 scripts/make_zip.py

**同梱の LICENSE と README は必ずこのプロジェクトのものを入れる。**
他製品からコピーしたまま別製品の記述が残っていた前例がある（kminpaku の LICENSE に
kshuisho の記述が残っていた）。最後に中身を並べて、製品名が混ざっていないか目で確かめる。
"""
from __future__ import annotations

import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOP = "kurage-yokaigo-nintei-navi"
OTHERS = ("kacnavi", "kminpaku", "kseido", "kecnavi", "kshuisho", "khokan", "kflood")


def main() -> int:
    data = ROOT / "php" / "knintei_data"
    files = [
        (ROOT / "php" / "LICENSE", "LICENSE"),
        (ROOT / "php" / "README.md", "README.md"),
        (ROOT / "php" / "knintei.php", "knintei.php"),
        (data / "knintei.sqlite", "knintei_data/knintei.sqlite"),
        (data / ".htaccess", "knintei_data/.htaccess"),
        (ROOT / "outputs" / "knintei_1200x630.png", "images/ogp/knintei.png"),
        (ROOT / "data" / "items.json", "data/items.json"),
        (ROOT / "data" / "details.json", "data/details.json"),
        (ROOT / "scripts" / "fetch_law.py", "scripts/fetch_law.py"),
        (ROOT / "scripts" / "parse_items.py", "scripts/parse_items.py"),
        (ROOT / "scripts" / "parse_detail.py", "scripts/parse_detail.py"),
        (ROOT / "scripts" / "fetch_cities.py", "scripts/fetch_cities.py"),
        (ROOT / "scripts" / "fetch_age.py", "scripts/fetch_age.py"),
        (ROOT / "scripts" / "build_db.py", "scripts/build_db.py"),
        (ROOT / "scripts" / "selftest.py", "scripts/selftest.py"),
        (ROOT / "docs" / "DATA.md", "docs/DATA.md"),
    ]
    missing = [str(s) for s, _ in files if not s.exists()]
    if missing:
        print("!! 無いファイル:", *missing, sep="\n  ", file=sys.stderr)
        return 1

    # 同梱の読み物に他製品の名前が残っていないか
    bad = []
    for src, name in files:
        if name in ("LICENSE", "README.md"):
            t = src.read_text(encoding="utf-8")
            for o in OTHERS:
                if re.search(o, t):
                    bad.append(f"{name} に「{o}」が残っている")
    if bad:
        print("!! " + "／".join(bad), file=sys.stderr)
        return 1

    out = ROOT / "outputs" / f"{TOP}.zip"
    out.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for src, name in files:
            z.write(src, f"{TOP}/{name}")
    print(f"→ {out}  {out.stat().st_size / 1e6:.1f}MB")
    with zipfile.ZipFile(out) as z:
        for i in z.infolist():
            print(f"  {i.file_size:>9,}B  {i.filename}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
