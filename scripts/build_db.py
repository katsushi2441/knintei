#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""data/*.json から公開用の SQLite を1本作る。

  /usr/bin/python3 scripts/build_db.py

出力: php/knintei_data/knintei.sqlite

heteml の SQLite には R*Tree も FTS5 も無いことがある。普通の表と索引だけを使う。
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
import sys
import unicodedata

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "php", "knintei_data", "knintei.sqlite")
HOPPOU = {"016951", "016969", "016977", "016985", "016993", "017001"}  # 北方領土6村

SCHEMA = """
PRAGMA journal_mode=DELETE;
DROP TABLE IF EXISTS meta; DROP TABLE IF EXISTS groups; DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS medical; DROP TABLE IF EXISTS kubun; DROP TABLE IF EXISTS cities;
DROP TABLE IF EXISTS laws; DROP TABLE IF EXISTS law_articles;
CREATE TABLE meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE groups(id TEXT PRIMARY KEY, name TEXT, n INTEGER, sort INTEGER);
CREATE TABLE items(id TEXT PRIMARY KEY, grp TEXT, name TEXT, multi INTEGER,
  options TEXT, definition TEXT, criteria TEXT, criteria_label TEXT, sort INTEGER);
CREATE INDEX ix_i_grp ON items(grp, sort);
CREATE TABLE medical(no INTEGER PRIMARY KEY, label TEXT);
CREATE TABLE kubun(id TEXT PRIMARY KEY, name TEXT, lo INTEGER, hi INTEGER,
  text TEXT, law TEXT, article TEXT, sort INTEGER);
CREATE TABLE cities(code TEXT PRIMARY KEY, pref_code TEXT, pref TEXT, city TEXT, kana TEXT,
  seirei_ku INTEGER, parent TEXT, slug TEXT,
  pop INTEGER, e65 INTEGER, e75 INTEGER, rate65 REAL, rate75 REAL,
  e65_rank INTEGER, e65_total INTEGER, rate65_rank INTEGER, rate65_total INTEGER);
CREATE INDEX ix_c_pref ON cities(pref_code, code);
CREATE INDEX ix_c_slug ON cities(pref, slug);
CREATE TABLE laws(name TEXT PRIMARY KEY, law_id TEXT, title TEXT, url TEXT, enforcement TEXT);
CREATE TABLE law_articles(law TEXT, article TEXT, title TEXT, caption TEXT, text TEXT,
  PRIMARY KEY(law, article));
"""

SHOREI = "要介護認定等に係る介護認定審査会による審査及び判定の基準等に関する省令"
# 区分の境目。**省令1条・2条から取った値を、条文と一緒に持つ。**
# 分数は条文の漢数字を数字にしただけで、足していない。
KUBUN = [
    ("yoshien1", "要支援1",   25,  32, "1"),
    ("yoshien2", "要支援2",   32,  50, "2"),
    ("yokaigo1", "要介護1",   32,  50, "1"),
    ("yokaigo2", "要介護2",   50,  70, "1"),
    ("yokaigo3", "要介護3",   70,  90, "1"),
    ("yokaigo4", "要介護4",   90, 110, "1"),
    ("yokaigo5", "要介護5",  110,   0, "1"),
]
KUBUN_NOTE = {
    "yoshien2": "要支援2と要介護1は、要介護認定等基準時間がどちらも32分以上50分未満。"
                "状態の維持・改善の見込みがあるかどうかで分かれる（省令2条1項2号）。",
}


def main() -> int:
    items = json.load(open(os.path.join(ROOT, "data", "items.json"), encoding="utf-8"))
    det = json.load(open(os.path.join(ROOT, "data", "details.json"), encoding="utf-8"))["details"]
    cit = json.load(open(os.path.join(ROOT, "data", "cities.json"), encoding="utf-8"))
    age = json.load(open(os.path.join(ROOT, "data", "age.json"), encoding="utf-8"))
    law = json.load(open(os.path.join(ROOT, "data", "law.json"), encoding="utf-8"))

    os.makedirs(os.path.dirname(DB), exist_ok=True)
    if os.path.exists(DB):
        os.remove(DB)
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA)

    con.executemany("INSERT INTO groups VALUES (?,?,?,?)",
                    [(g["id"], g["name"], g["n"], i) for i, g in enumerate(items["groups"])])
    rows = []
    for i, it in enumerate(items["items"]):
        d = det.get(it["id"], {})
        rows.append((it["id"], it["group"], it["name"], 1 if it["multi"] else 0,
                     json.dumps(it["options"], ensure_ascii=False),
                     d.get("definition", ""), d.get("criteria", ""),
                     d.get("criteria_label", "選択肢の選択基準"), i))
    con.executemany("INSERT INTO items VALUES (?,?,?,?,?,?,?,?,?)", rows)
    con.executemany("INSERT INTO medical VALUES (?,?)",
                    [(m["no"], m["label"]) for m in items["medical"]])

    arts = law[SHOREI]["articles"]
    con.executemany("INSERT INTO kubun VALUES (?,?,?,?,?,?,?,?)",
                    [(k[0], k[1], k[2], k[3], KUBUN_NOTE.get(k[0], ""), SHOREI, k[4], i)
                     for i, k in enumerate(KUBUN)])

    # 引く条文だけを持つ
    used = {(SHOREI, "1"), (SHOREI, "2"), (SHOREI, "3"),
            ("介護保険法", "27"), ("介護保険法", "9"), ("介護保険法", "43"), ("介護保険法", "7")}
    con.executemany("INSERT INTO laws VALUES (?,?,?,?,?)",
                    [(n, law[n]["law_id"], law[n]["title"], law[n]["url"],
                      law[n].get("amendment_enforcement_date")) for n in {n for n, _ in used}])
    con.executemany("INSERT INTO law_articles VALUES (?,?,?,?,?)",
                    [(n, a, law[n]["articles"][a]["title"], law[n]["articles"][a]["caption"],
                      law[n]["articles"][a]["text"]) for n, a in used if a in law[n]["articles"]])

    ku_parents = {c["city"].split("市")[0] + "市" for c in cit["cities"] if c["seirei_ku"]}
    crows, skipped = [], 0
    for c in cit["cities"]:
        if c["code"] in HOPPOU:
            skipped += 1
            continue
        a = age["cities"].get(c["code"], {})
        crows.append((c["code"], c["pref_code"], c["pref"], c["city"],
                      unicodedata.normalize("NFKC", c["kana"]),
                      1 if c["seirei_ku"] else 0,
                      c["city"].split("市")[0] + "市" if c["seirei_ku"] else "", c["city"],
                      a.get("pop"), a.get("e65"), a.get("e75"), a.get("rate65"), a.get("rate75"),
                      a.get("e65_rank"), a.get("e65_total"), a.get("rate65_rank"), a.get("rate65_total")))
    con.executemany("INSERT INTO cities VALUES (" + ",".join("?" * 17) + ")", crows)

    m = age["meta"]
    con.executemany("INSERT INTO meta VALUES (?,?)", [
        ("items_source", items["source"]), ("items_source_url", items["source_url"]),
        ("count_note", items["count_note"]),
        ("age_asof", m["asof"]), ("age_source", m["source"]), ("age_source_url", m["source_url"]),
        ("e65_national", str(m["e65_national"])), ("pop_national", str(m["pop_national"])),
        ("rate65_national", str(m["rate65_national"])), ("e65_median", str(int(m["e65_median"]))),
        ("rate65_median", str(m["rate65_median"])),
        ("n_items", str(len(rows))), ("n_options", str(sum(len(json.loads(r[4])) for r in rows))),
        ("n_medical", str(len(items["medical"]))), ("n_cities", str(len(crows))),
        ("n_seirei", str(len(ku_parents))),
    ])
    con.commit()
    con.execute("VACUUM")
    con.close()
    print(f"→ {DB} {os.path.getsize(DB):,}B")
    print(f"  設問 {len(rows)}・選択肢 {sum(len(json.loads(r[4])) for r in rows)}・"
          f"特別な医療 {len(items['medical'])}・区分 {len(KUBUN)}")
    print(f"  市区町村 {len(crows):,}（北方領土 {skipped}村を除外）／条文 {len(used)}件")
    return 0


if __name__ == "__main__":
    sys.exit(main())
