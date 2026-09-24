#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""認定調査の基本調査74項目を、厚労省のテキストから取り出す。

  /usr/bin/python3 scripts/parse_items.py

出典: 厚生労働省「認定調査員テキスト2009改訂版（令和6年4月改訂）」
      https://www.mhlw.go.jp/content/001249525.pdf
      政府標準利用規約(第2.0版)。出典表示のうえ商用利用可。

**項目名も選択肢も、言い換えずに原文のまま取る。** 認定調査は選択肢の文言そのものが
判断基準になっている（「つかまらないでできる」と「何かにつかまればできる」の差が
そのまま点になる）。要約すると別のものになる。

巻末の「認定調査票（基本調査）」から取る。本文側の解説にも同じ項目が出てくるが、
そちらは説明文が混ざるので使わない。
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PDF = os.path.join(ROOT, "data", "nintei_text.pdf")
TXT = os.path.join(ROOT, "data", "nintei_text.txt")
OUT = os.path.join(ROOT, "data", "items.json")
URL = "https://www.mhlw.go.jp/content/001249525.pdf"
UA = {"User-Agent": "knintei/1.0 (+https://kurage.exbridge.jp/)"}

# 5群の構成（テキスト本文に明記。項目数は検算に使う）
GROUPS = [
    ("1", "身体機能・起居動作", 13),
    ("2", "生活機能", 12),
    ("3", "認知機能", 9),
    ("4", "精神・行動障害", 15),
    ("5", "社会生活への適応", 6),
]
# その他（過去14日間にうけた特別な医療）12項目。テキスト本文に明記。
MEDICAL_N = 12
# **「74項目」の数え方。** 設問は第1〜5群で55問しかない。74になるのは、
# 1-1 麻痺（左上肢・右上肢・左下肢・右下肢・その他の5）と 1-2 拘縮（肩・股・膝・その他の4）を
# 部位ごとに1項目と数えるため。55 - 2 + 5 + 4 = 62、これに特別な医療12を足して74。
# 障害高齢者・認知症高齢者の日常生活自立度2つは74の外（一次判定には使わない）。
EXPAND = {"1-1": 5, "1-2": 4}


def ensure_text() -> str:
    if not os.path.exists(PDF):
        with urllib.request.urlopen(urllib.request.Request(URL, headers=UA), timeout=600) as r:
            open(PDF, "wb").write(r.read())
        print(f"  取得 {os.path.getsize(PDF)/1e6:.1f}MB")
    if not os.path.exists(TXT):
        subprocess.run(["pdftotext", "-layout", PDF, TXT], check=True)
    return open(TXT, encoding="utf-8", errors="replace").read()


def main() -> int:
    raw = ensure_text()
    i = raw.rfind("認定調査票（基本調査）")
    if i < 0:
        print("!! 巻末の認定調査票が見つからない", file=sys.stderr)
        return 1
    body = raw[i:]

    # 「1-1 麻痺等の有無について、…」の見出しと、その下の「1.○○ 2.○○」の並び
    # 見出しは「1-1 麻痺等の有無について、…○印をつけてください｡（複数回答可）」の1行。
    # 複数回答かどうかは**この行だけ**で決める。区切りの後ろまで見ると、
    # 次の節（特別な医療）の「複数回答可」を拾って 5-6 簡単な調理が複数回答になった。
    head = re.compile(r"^\s*(\d)-(\d{1,2})\s+(.+?)について[、,]([^\n]*)", re.M)
    marks = list(head.finditer(body))
    print(f"  項目の見出し {len(marks)}件")

    items = []
    for n, m in enumerate(marks):
        seg = body[m.end(): marks[n + 1].start() if n + 1 < len(marks) else m.end() + 1200]
        seg = re.sub(r"\n\s*\d{2,3}\s*\n", "\n", seg)          # ページ番号を落とす
        # 「（複数回答可）」は行内にあるとは限らない（1-2 拘縮は次の行に折り返している）。
        # 見出し行に必ずある「すべてに○印」／「一つだけ○印」で決める。
        multi = "すべてに" in m.group(4) or "複数回答可" in m.group(4)
        opts = []
        # **次の番号の前に空白があるとは限らない。** 5-3 は
        # 「1.できる（特別な場合でもできる）2.特別な場合を除いてできる」と詰まっていて、
        # 空白2つを区切りにしていたら選択肢が1つも取れなかった（2026-09-25 実測）。
        for om in re.finditer(r"(?<![\d.])([1-9])\s*[.．]\s*([^\n]{1,44}?)(?=\s*[1-9]\s*[.．]|\s*$|\n)", seg):
            t = om.group(2).strip()
            t = re.sub(r"\s+", "", t)
            if t and not t.isdigit():
                opts.append({"no": int(om.group(1)), "label": t})
        # 番号が1から連番になっているところまでを選択肢とみなす
        cut = []
        for o in opts:
            if o["no"] == len(cut) + 1:
                cut.append(o)
            elif cut:
                break
        items.append({"id": f"{m.group(1)}-{m.group(2)}", "group": m.group(1),
                      "name": m.group(3).strip(), "multi": multi,
                      "options": cut})

    # 74項目に絞る（巻末には第6群や自立度も続く）
    base = [x for x in items if x["group"] in {g[0] for g in GROUPS}]
    seen, uniq = set(), []
    for x in base:
        if x["id"] in seen:
            continue
        seen.add(x["id"]); uniq.append(x)

    print("  群ごとの数（期待値と突き合わせ）")
    ng = 0
    for g, name, want in GROUPS:
        got = [x for x in uniq if x["group"] == g]
        mark = "○" if len(got) == want else "✗"
        if len(got) != want:
            ng += 1
        print(f"    {mark} 第{g}群 {name:<16} {len(got):>2} / {want}")
    total = len(uniq)
    print(f"  設問の合計 {total} / 55 {'○' if total == 55 else '✗'}")
    bad = [x["id"] for x in uniq if len(x["options"]) < 2]
    if bad:
        print(f"  !! 選択肢が取れていない項目: {'・'.join(bad)}", file=sys.stderr)
    if ng or total != 55 or bad:
        print("\n数が合わない。公開しない。", file=sys.stderr)
        json.dump(uniq, open(OUT + ".partial", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
        return 1

    # 特別な医療12項目
    mi = body.rfind("処置内容")
    med = []
    if mi >= 0:
        for om in re.finditer(r"(?<![\d.])(\d{1,2})\s*[.．]\s*([^\n]{1,44}?)(?=\s{2,}\d{1,2}\s*[.．]|\s*$|\n)",
                              body[mi: mi + 900]):
            n_, t = int(om.group(1)), re.sub(r"\s+", "", om.group(2))
            if n_ == len(med) + 1 and t:
                med.append({"no": n_, "label": t})
    print(f"  特別な医療 {len(med)} / {MEDICAL_N} {'○' if len(med) == MEDICAL_N else '✗'}")

    # 「74項目」の検算
    expanded = sum(EXPAND.get(x["id"], 1) for x in uniq)
    total74 = expanded + len(med)
    print(f"  74項目の検算: 第1〜5群を部位ごとに数えて {expanded} ＋ 特別な医療 {len(med)} = "
          f"{total74} {'○' if total74 == 74 else '✗'}")
    if len(med) != MEDICAL_N or total74 != 74:
        print("\n数が合わない。公開しない。", file=sys.stderr)
        return 1

    json.dump({"source": "厚生労働省 認定調査員テキスト2009改訂版（令和6年4月改訂）",
               "source_url": URL,
               "groups": [{"id": g, "name": n, "n": c} for g, n, c in GROUPS],
               "items": uniq, "medical": med,
               "count_note": "基本調査74項目 = 第1〜5群の設問55問（麻痺を部位ごとに5、拘縮を4と数えて62）＋ 特別な医療12",
               }, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"→ {OUT} 設問{len(uniq)}問・選択肢{sum(len(x['options']) for x in uniq)}個")
    return 0


if __name__ == "__main__":
    sys.exit(main())
