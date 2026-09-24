#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""要介護認定の区分・期限・支給限度の根拠を、法令の条文そのもので裏取りする。

  /usr/bin/python3 scripts/fetch_law.py            # data/law.json を作る
  /usr/bin/python3 scripts/fetch_law.py 戸籍法 86  # 1条だけ見る

**なぜ要るか。** 「死亡届は7日以内」と書いたページは無数にあるが、どの条文かを出しているものは少ない。
期限は改正で動く（相続登記の3年は2024年4月1日施行の不動産登記法76条の2で、それ以前は義務ですらなかった）。
条文を持っておけば、改正日と施行日で自分の表を検算できる。

出典: e-Gov法令検索 法令API v2（デジタル庁）https://laws.e-gov.go.jp/api/2/
      コンテンツは政府標準利用規約(第2.0版)。出典表示のうえ商用利用可。
"""
from __future__ import annotations

import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "data", "law.json")
API = "https://laws.e-gov.go.jp/api/2/law_data/"
UA = {"User-Agent": "kacnavi/1.0 (+https://kurage.exbridge.jp/)"}

# 手続きの期限の根拠にする法令。法令番号で引く（題名で引くと改称や同名で外す）。
LAWS = {
    # 区分の境目（要介護認定等基準時間）はこの省令の第1条に全部ある
    "要介護認定等に係る介護認定審査会による審査及び判定の基準等に関する省令": "平成十一年厚生省令第五十八号",
    "介護保険法": "平成九年法律第百二十三号",
    "介護保険法施行規則": "平成十一年厚生省令第三十六号",
    "健康保険法": "大正十一年法律第七十号",
    "高齢者の医療の確保に関する法律": "昭和五十七年法律第八十号",
}

KANSUJI = "〇一二三四五六七八九"


def num_to_kanji(n: int) -> str:
    """86 → 八十六。条文番号の見出し（第八十六条）を組み立てるため。"""
    if n < 10:
        return KANSUJI[n]
    if n < 100:
        t, o = divmod(n, 10)
        return ("十" if t == 1 else KANSUJI[t] + "十") + (KANSUJI[o] if o else "")
    h, r = divmod(n, 100)
    return ("百" if h == 1 else KANSUJI[h] + "百") + (num_to_kanji(r) if r else "")


def text_of(node) -> str:
    """法令APIのJSON木から文字列だけを拾う。"""
    if isinstance(node, str):
        return node
    if isinstance(node, list):
        return "".join(text_of(x) for x in node)
    if isinstance(node, dict):
        if node.get("tag") == "Ruby":  # 振り仮名は本文だけ取る
            ch = node.get("children") or []
            return "".join(text_of(c) for c in ch if not (isinstance(c, dict) and c.get("tag") == "Rt"))
        return text_of(node.get("children") or [])
    return ""


def walk(node, tag: str):
    if isinstance(node, dict):
        if node.get("tag") == tag:
            yield node
        for c in node.get("children") or []:
            yield from walk(c, tag)
    elif isinstance(node, list):
        for c in node:
            yield from walk(c, tag)


def fetch(law_num: str) -> dict:
    url = API + urllib.parse.quote(law_num, safe="")
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120) as r:
        return json.loads(r.read().decode("utf-8"))


def articles(doc: dict) -> dict[str, dict]:
    """{"86": {"title": "第八十六条", "caption": "…", "text": "…"}} を返す。

    **本則（MainProvision）の中だけを見る。** 附則にも第25条・第27条があり、木を素通しで
    歩くと後から出てくる附則が本則を上書きする。実際それで住民基本台帳法25条が
    「罰則に関する経過措置」に、相続税法27条が「配偶者に対する相続税額の軽減等に関する
    経過措置」になっていた（2026-09-24 実測）。検算しなければ、そのまま期限の根拠として
    出すところだった。
    """
    out = {}
    main = list(walk(doc["law_full_text"], "MainProvision")) or [doc["law_full_text"]]
    for a in walk(main, "Article"):
        num = (a.get("attr") or {}).get("Num") or ""
        if not num:
            continue
        title = caption = ""
        body = []
        for c in a.get("children") or []:
            if not isinstance(c, dict):
                continue
            if c["tag"] == "ArticleTitle":
                title = text_of(c)
            elif c["tag"] == "ArticleCaption":
                caption = text_of(c).strip("（）")
            elif c["tag"] == "Paragraph":
                body.append(re.sub(r"\s+", " ", text_of(c)).strip())
        out[num] = {"title": title, "caption": caption, "text": "\n".join(body)}
    return out


def main() -> int:
    if len(sys.argv) == 3:  # 1条だけ確かめる
        name, art = sys.argv[1], sys.argv[2]
        d = fetch(LAWS[name])
        a = articles(d).get(art)
        if not a:
            print(f"{name} 第{art}条 は無い", file=sys.stderr)
            return 1
        print(f"{name}（{d['revision_info']['law_title']}）{a['title']}（{a['caption']}）")
        print(a["text"][:900])
        return 0

    data = {}
    for name, law_num in LAWS.items():
        try:
            d = fetch(law_num)
        except Exception as e:
            print(f"  !! {name}: {e}", file=sys.stderr)
            continue
        ri = d["revision_info"]
        arts = articles(d)
        data[name] = {
            "law_num": law_num,
            "law_id": d["law_info"]["law_id"],
            "title": ri["law_title"],
            "amendment_promulgate_date": ri.get("amendment_promulgate_date"),
            "amendment_enforcement_date": ri.get("amendment_enforcement_date"),
            "url": "https://laws.e-gov.go.jp/law/" + d["law_info"]["law_id"],
            "articles": arts,
        }
        print(f"  {name}: {len(arts)}条 施行 {ri.get('amendment_enforcement_date')}")
        time.sleep(1)
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(data, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"→ {OUT} 法令 {len(data)}本")
    return 0


if __name__ == "__main__":
    sys.exit(main())
