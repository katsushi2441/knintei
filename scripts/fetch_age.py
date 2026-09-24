#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""市区町村ごとの人口と、65歳以上・75歳以上の人数を取る。

  /usr/bin/python3 scripts/fetch_age.py

出典: 総務省「住民基本台帳に基づく人口、人口動態及び世帯数」年齢別人口（市区町村別）
      令和8年1月1日現在
      https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html
      政府標準利用規約(第2.0版)。出典表示のうえ商用利用可。

**なぜ要るか。** 市区町村ページを1,900枚作っても、中身が同じなら索引に入らない。
「この市には65歳以上が何人いて、何人に1人か」は、要介護認定の話に直接つながっていて
1枚ずつ違う。**介護保険の第1号被保険者は65歳以上**（介護保険法9条1号）なので、
その市区町村で認定を申請しうる人数の土台にあたる。

数字は足さない。公表されている5歳刻みを足し合わせて位置を出すだけ。
"""
from __future__ import annotations

import importlib.util
import json
import os
import re
import statistics
import sys
import urllib.request
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
XLSX = os.path.join(ROOT, "data", "juki_age.xlsx")
OUT = os.path.join(ROOT, "data", "age.json")
URL = "https://www.soumu.go.jp/main_content/000892953.xlsx"
UA = {"User-Agent": "knintei/1.0 (+https://kurage.exbridge.jp/)"}
ASOF = "2026-01-01"


def main() -> int:
    if not os.path.exists(XLSX):
        with urllib.request.urlopen(urllib.request.Request(URL, headers=UA), timeout=600) as r:
            open(XLSX, "wb").write(r.read())

    spec = importlib.util.spec_from_file_location("fc", os.path.join(ROOT, "scripts", "fetch_cities.py"))
    fc = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fc)
    rows = fc.sheet_rows(zipfile.ZipFile(XLSX), "xl/worksheets/sheet1.xml")

    # 見出しから年齢帯の列を拾う。**列番号を決め打ちしない**（静かに壊れる）
    band = rows[1]
    cols = {}
    for i, v in enumerate(band):
        m = re.match(r"(\d+)歳～(\d+)歳", v or "")
        if m:
            cols[i] = (int(m.group(1)), int(m.group(2)))
        elif re.match(r"(\d+)歳以上", v or ""):
            cols[i] = (int(re.match(r"(\d+)", v).group(1)), 200)
        elif v == "総数":
            cols[i] = None
    total_col = next(i for i, v in cols.items() if v is None)
    print(f"  年齢帯の列 {len(cols)-1}本 / 総数は{total_col}列目")
    print("  最年長の帯:", band[max(i for i, v in cols.items() if v)])

    cj = json.load(open(os.path.join(ROOT, "data", "cities.json"), encoding="utf-8"))["cities"]
    known = {c["code"] for c in cj}
    ku_parents = {c["city"].split("市")[0] + "市" for c in cj if c["seirei_ku"]}
    seirei_total = {c["code"] for c in cj if c["city"] in ku_parents}

    out = {}
    for r in rows:
        code = (r[0] or "").strip()
        if code not in known or (len(r) > 3 and r[3] != "計"):
            continue

        def num(i):
            try:
                return int(float((r[i] or "").replace(",", "")))
            except (ValueError, IndexError):
                return 0
        pop = num(total_col)
        e65 = sum(num(i) for i, b in cols.items() if b and b[0] >= 65)
        e75 = sum(num(i) for i, b in cols.items() if b and b[0] >= 75)
        if pop:
            out[code] = {"pop": pop, "e65": e65, "e75": e75,
                         "rate65": round(e65 / pop * 100, 1), "rate75": round(e75 / pop * 100, 1)}

    # 全国での位置（数字は足さず、並べ替えて出すだけ）
    rankable = {c: v for c, v in out.items() if c not in seirei_total}
    for key, rk in (("e65", "e65_rank"), ("rate65", "rate65_rank")):
        order = sorted(rankable.items(), key=lambda kv: -kv[1][key])
        for i, (c, _) in enumerate(order, 1):
            out[c][rk] = i
            out[c][rk.replace("_rank", "_total")] = len(order)

    med65 = statistics.median([v["e65"] for v in rankable.values()])
    medr = statistics.median([v["rate65"] for v in rankable.values()])
    nat65 = sum(v["e65"] for v in rankable.values())
    natpop = sum(v["pop"] for v in rankable.values())
    meta = {"asof": ASOF, "source": "総務省 住民基本台帳に基づく人口、人口動態及び世帯数（年齢別人口）",
            "source_url": "https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html",
            "e65_median": med65, "rate65_median": medr,
            "e65_national": nat65, "pop_national": natpop,
            "rate65_national": round(nat65 / natpop * 100, 1)}
    json.dump({"meta": meta, "cities": out}, open(OUT, "w", encoding="utf-8"), ensure_ascii=False)
    print(f"→ {OUT} {len(out):,}団体")
    print(f"  全国: 人口 {natpop:,} / 65歳以上 {nat65:,}（{meta['rate65_national']}%）"
          f" / 中央値 {med65:,.0f}人・{medr}%")
    for c, nm in (("231061", "名古屋市中区"), ("131016", "千代田区"), ("014605", "夕張市")):
        v = out.get(c)
        if v:
            print(f"  {nm}: 人口{v['pop']:,} 65歳以上{v['e65']:,}（{v['rate65']}%・"
                  f"全国{v['rate65_rank']}位/{v['rate65_total']}）75歳以上{v['e75']:,}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
