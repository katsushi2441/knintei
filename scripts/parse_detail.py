#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""項目ごとの「調査項目の定義」と「選択肢の選択基準」を取り出す。

  /usr/bin/python3 scripts/parse_detail.py

出典: 厚生労働省 認定調査員テキスト2009改訂版（令和6年4月改訂）

**なぜ要るか。** 項目名と選択肢だけの55ページは薄い。実際に役に立つのは
「寝返りとは、きちんと横向きにならなくても体の向きを変えて安定した状態に
なれるか」という**定義のほう**。ここを外すと、調べに来た人の疑問が残る。

**原文のまま取る。要約しない。** 認定調査は文言そのものが判断基準になっている。
PDFの折り返しで切れた行はつなぐが、言葉は変えない。
"""
from __future__ import annotations

import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TXT = os.path.join(ROOT, "data", "nintei_text.txt")
ITEMS = os.path.join(ROOT, "data", "items.json")
OUT = os.path.join(ROOT, "data", "details.json")


def clean(block: str) -> str:
    """ページ番号とヘッダを落とし、折り返しをつなぐ。**語は変えない。**"""
    out = []
    for ln in block.splitlines():
        t = ln.rstrip()
        if not t.strip():
            out.append("")
            continue
        if re.fullmatch(r"\s*\d{1,3}\s*", t):          # ページ番号だけの行
            continue
        if re.match(r"\s*第[1-5]群\s+\d-\d", t):        # 柱の見出し
            continue
        out.append(t.strip())
    # 空行で段落に切り、段落の中は折り返しなのでつなぐ
    paras, cur = [], []
    for t in out:
        if t:
            cur.append(t)
        elif cur:
            paras.append("".join(cur)); cur = []
    if cur:
        paras.append("".join(cur))
    return "\n".join(p for p in paras if len(p) > 1)


def main() -> int:
    raw = open(TXT, encoding="utf-8", errors="replace").read()
    items = json.load(open(ITEMS, encoding="utf-8"))["items"]

    # 各項目の解説は「第n群  n-m 名前（評価軸）」の柱で始まる。**巻末の調査票より前**だけを見る。
    body = raw[: raw.rfind("認定調査票（基本調査）")]
    heads = []
    for m in re.finditer(r"^\s*第([1-5])群\s+(\d-\d{1,2})\s+(\S.*?)$", body, re.M):
        heads.append((m.start(), m.group(2)))
    print(f"  解説の柱 {len(heads)}か所")

    # 同じ項目が複数回出る（表・一覧・解説）。**「(1) 調査項目の定義」を含む塊だけ**を採る。
    got = {}
    for i, (pos, iid) in enumerate(heads):
        end = heads[i + 1][0] if i + 1 < len(heads) else len(body)
        seg = body[pos:end]
        if "調査項目の定義" not in seg:
            continue
        d = re.search(r"調査項目の定義\s*(.*?)(?=\(\s*2\s*\)|（２）|選択肢の選択基準)", seg, re.S)
        # **(2) の見出しは群によって違う。** 第1〜3群・第5群は「選択肢の選択基準」、
        # 第4群は「調査上の留意点及び特記事項の記載例」。片方だけ見ていて第4群の15項目が
        # 全部空になった（2026-09-25 実測）。どちらでも拾う。
        c = re.search(r"(?:選択肢の選択基準|調査上の留意点及び特記事項の記載例)\s*(.*?)"
                      r"(?=\(\s*[34]\s*\)|（[３４]）|異なった選択|$)", seg, re.S)
        rec = {"definition": clean(d.group(1)) if d else "", "criteria": clean(c.group(1)) if c else "",
               "criteria_label": ("調査上の留意点及び特記事項の記載例"
                                  if c and "留意点" in seg[:seg.index(c.group(1))][-60:] else "選択肢の選択基準")}
        if rec["definition"] and (iid not in got or len(rec["definition"]) > len(got[iid]["definition"])):
            got[iid] = rec

    ng = [x["id"] for x in items if x["id"] not in got]
    print(f"  定義が取れた項目 {len(got)} / {len(items)}")
    if ng:
        print(f"  !! 取れなかった: {'・'.join(ng)}", file=sys.stderr)
    # **短い定義があっても異常ではない。** 3-9 外出すると戻れない・4-5 しつこく同じ話をする は
    # 原文が「◯◯行動の頻度を評価する項目である。」の1行しかない（2026-09-25 に原典で確認）。
    # 閾値を30字にしていて、正しく取れているものを不合格にしていた。
    short = [k for k, v in got.items() if len(v["definition"]) < 15]
    if short:
        print(f"  !! 定義が短すぎる: {'・'.join(short)}", file=sys.stderr)
    nocrit = [k for k, v in got.items() if len(v["criteria"]) < 20]
    if nocrit:
        print(f"  !! 選択基準が取れていない: {'・'.join(nocrit)}", file=sys.stderr)
    if ng or short or nocrit:
        print("\n数が合わない。公開しない。", file=sys.stderr)
        json.dump(got, open(OUT + ".partial", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
        return 1

    json.dump({"source": "厚生労働省 認定調査員テキスト2009改訂版（令和6年4月改訂）",
               "details": got}, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    avg = sum(len(v["definition"]) for v in got.values()) / len(got)
    print(f"→ {OUT} 定義の平均 {avg:.0f}字 / 選択基準がある項目 "
          f"{sum(1 for v in got.values() if v['criteria'])}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
