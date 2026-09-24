#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""公開する前に、画面が嘘をついていないかを確かめる。

  /usr/bin/python3 scripts/selftest.py

**kminpaku で、京都府の全住所を「未収録」と出したまま公開しかけた。**
市区町村名を最短一致で切って「京都」と読んでいたのが原因で、画面を1つも見ていなかった。
ここでは PHP を実際に走らせ、出てきた HTML の中身を1件ずつ見る。
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.path.join(ROOT, "php", "knintei.php")


# **PHP の CLI は QUERY_STRING から $_GET を作らない。** `php -f` で試すと、
# 条件を1つも選んでいない扱いになり、絞り込みの検査が全部すり抜ける（2026-09-24 実測）。
# 本番と同じ経路で見るために、組み込みサーバーを立てて HTTP で叩く。
_SRV = None
_PORT = 18394


def _server():
    global _SRV
    if _SRV is None:
        router = os.path.join(ROOT, "scripts", "_router.php")
        open(router, "w", encoding="utf-8").write(
            "<?php $u=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);"
            "if(strpos($u,'/knintei.php')===0){$_SERVER['PATH_INFO']=substr($u,strlen('/knintei.php'));"
            "require __DIR__.'/../php/knintei.php';return true;} return false;\n")
        _SRV = subprocess.Popen(["php", "-S", f"127.0.0.1:{_PORT}", "-t", os.path.join(ROOT, "php"), router],
                                stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        import atexit, time
        atexit.register(_SRV.terminate)
        for _ in range(60):
            try:
                urllib.request.urlopen(f"http://127.0.0.1:{_PORT}/knintei.php/", timeout=2).read()
                break
            except Exception:
                time.sleep(0.2)
    return _SRV


def get(path: str, qs: str = "") -> str:
    _server()
    url = f"http://127.0.0.1:{_PORT}/knintei.php" + urllib.parse.quote(path) + (("?" + qs) if qs else "")
    try:
        with urllib.request.urlopen(url, timeout=60) as r:
            return r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:      # 404 も中身を見たい
        return e.read().decode("utf-8", "replace")


def strip(html: str) -> str:
    """見える文字だけにする。**script と style の中身は先に落とす** ——
    構造化データ（JSON-LD）の FAQ 本文に手続き名が入っており、落とさないと
    『出ていないはずのものが出ている』と誤判定する（2026-09-24 実測）。"""
    html = re.sub(r"<(script|style)\b[^>]*>.*?</\1>", " ", html, flags=re.S | re.I)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html))


CHECKS = []


def check(name):
    def deco(fn):
        CHECKS.append((name, fn))
        return fn
    return deco


@check("55問すべてが一覧表に出る")
def t1():
    t = strip(get("/"))
    assert "全55問" in t, "件数が出ていない"
    for nm in ("寝返り", "移乗", "意思の伝達", "物を盗られたなどと被害的になること", "簡単な調理"):
        assert nm in t, f"{nm} が無い"


@check("区分を当てないと明記し、根拠の条文を出している")
def t2():
    t = strip(get("/kubun"))
    assert "厚生労働大臣の定める方法により推計される時間" in t, "省令3条の条文が出ていない"
    assert "係数は公開されていません" in t
    assert "laws.e-gov.go.jp" in get("/kubun"), "e-Gov へのリンクが無い"
    # 区分を断定していないこと。**「あなたは要介護2です」は言わない理由の説明として
    # 出している**ので、その文が「書いてしまうと」と続くかで見分ける。
    import re
    for m in re.finditer(r"あなたは要介護.{0,30}", t):
        assert "書いてしまうと" in m.group(0) or "書きません" in m.group(0), \
            f"区分を断定している: {m.group(0)}"
    for bad in ("判定結果は", "推定区分"):
        assert bad not in t, f"区分を断定する文言がある: {bad}"


@check("7区分すべての境目が条文どおり出る")
def t3():
    t = strip(get("/kubun"))
    for nm, rng in (("要支援1", "25分以上32分未満"), ("要支援2", "32分以上50分未満"),
                    ("要介護1", "32分以上50分未満"), ("要介護2", "50分以上70分未満"),
                    ("要介護3", "70分以上90分未満"), ("要介護4", "90分以上110分未満"),
                    ("要介護5", "110分以上")):
        assert nm in t and rng in t, f"{nm} {rng} が出ていない"


@check("項目ページに定義と選択基準が原文で出る")
def t4():
    t = strip(get("/item/1-3"))
    assert "きちんと横向きにならなくても" in t, "定義が原文で出ていない"
    assert "何にもつかまらないで、寝返り" in t, "選択基準が出ていない"
    assert "つかまらないでできる" in t and "何かにつかまればできる" in t


@check("第4群は見出しが違うが、中身が出ている")
def t5():
    t = strip(get("/item/4-1"))
    assert "調査上の留意点及び特記事項の記載例" in t, "第4群の見出しが出ていない"
    assert "食べ物に毒が入っている" in t, "第4群の本文が空になっている"


@check("群ページに正しい問数が出る")
def t6():
    for g, n_, nm in (("1", 13, "身体機能・起居動作"), ("2", 12, "生活機能"), ("3", 9, "認知機能"),
                      ("4", 15, "精神・行動障害"), ("5", 6, "社会生活への適応")):
        t = strip(get("/group/" + g))
        assert nm in t, f"第{g}群の名前が無い"
        assert f"{n_}問" in t, f"第{g}群が{n_}問になっていない"


@check("74項目の数え方を説明している")
def t7():
    t = strip(get("/about"))
    assert "74" in t and "麻痺" in t and "拘縮" in t and "特別な医療" in t


@check("市区町村ページに、その市区町村にしか書けない数字が出る")
def t8():
    a = strip(get("/city/愛知県/名古屋市中区"))
    b = strip(get("/city/北海道/夕張市"))
    assert "17,812" in a, "名古屋市中区の65歳以上17,812が出ていない"
    assert "3,131" in b, "夕張市の65歳以上3,131が出ていない"
    assert a != b
    assert "名古屋市中区役所" in a, "政令市の区なのに区役所と書いていない"
    # 夕張市の高齢化率は全国最高水準。54.3%が出ていれば数字が生きている
    assert "54.3" in b, "夕張市の高齢化率54.3%が出ていない"


@check("市区町村ページに課名や電話を書いていない（転載しない線）")
def t9():
    t = strip(get("/city/愛知県/名古屋市中区"))
    assert "公式ページか電話で確かめて" in t
    import re
    assert not re.search(r"0\d{1,4}-\d{1,4}-\d{4}", t), "電話番号を載せている"


@check("都道府県ページの合計が、区を二重に数えていない")
def t10():
    import re
    t = strip(get("/pref/愛知県"))
    m = re.search(r"65歳以上が([\d,]+)人", t)
    assert m, "合計が出ていない"
    v = int(m.group(1).replace(",", ""))
    # 愛知県の65歳以上はおよそ190万人台。区を重ねると250万を超える
    assert 1_700_000 < v < 2_200_000, f"愛知県の合計が {v:,}人。区を二重に数えていないか"


@check("サイトマップに全ページが入り、索引の中に索引を入れていない")
def t11():
    import re
    x = get("/sitemap.xml")
    locs = re.findall(r"<loc>([^<]+)</loc>", x)
    assert "<sitemapindex" not in x, "索引の中に索引を入れている（仕様違反）"
    assert len(locs) > 1900, f"URLが {len(locs)}件しかない"
    assert len(set(locs)) == len(locs), "重複したURLがある"
    assert sum(1 for u in locs if "/city/" in u) == 1912
    assert sum(1 for u in locs if "/item/" in u) == 55


@check("北方領土の6村のページを作っていない")
def t12():
    x = get("/sitemap.xml")
    for v in ("色丹村", "留夜別村", "蘂取村"):
        assert v not in x, f"{v} のページを作っている（役場が機能していない）"


@check("言葉で調査項目を探せる")
def t13():
    import urllib.parse as up
    t = strip(get("/", "q=" + up.quote("寝返り")))
    assert "1-3" in t and "寝返り" in t
    t2 = strip(get("/", "q=" + up.quote("薬")))
    assert "薬の内服" in t2


@check("API が JSON を返す")
def t14():
    import json
    d = json.loads(get("/api/items"))
    assert d["count"] == 55
    ids = [i["id"] for i in d["items"]]
    assert "1-1" in ids and "5-6" in ids
    assert all(i["definition"] for i in d["items"]), "定義が空の項目がある"


@check("無いページは404を返す")
def t15():
    for p in ("/item/9-9", "/group/9", "/city/愛知県/存在しない市", "/pref/架空県"):
        t = strip(get(p))
        assert "見つかりません" in t, f"{p} が404になっていない"


@check("すべてのページが横スクロールしない作りになっている")
def t16():
    html = get("/city/愛知県/名古屋市中区")
    assert "width:min(920px,100% - 32px)" in html, "外枠の横幅指定が無い"
    assert 'class="tscroll"' in get("/pref/愛知県"), "表を包む overflow-x が無い"


@check("画像を縦横に変形させていない")
def t17():
    css = get("/")
    import re
    for m in re.finditer(r"\.hero img\{([^}]*)\}", css):
        if "width" in m.group(1) and "height:auto" not in m.group(1):
            raise AssertionError(f"幅を変える img に height:auto が無い: {m.group(1)}")
    assert "object-fit:contain" in css and "object-fit:cover" not in css


@check("出品前は、商品ページへの空リンクを出さない")
def t18():
    for p in ("/", "/kubun", "/city/愛知県/名古屋市中区"):
        html = get(p)
        assert "app.php?id=&" not in html and "app.php?id=\"" not in html, f"{p} に空の商品リンクがある"


def main() -> int:
    ng = 0
    for name, fn in CHECKS:
        try:
            fn()
            print(f"  \u25cb {name}")
        except AssertionError as e:
            print(f"  \u2717 {name}\n      {e}")
            ng += 1
        except Exception as e:
            print(f"  \u2717 {name}\n      {type(e).__name__}: {e}")
            ng += 1
    print(f"\n{len(CHECKS)}件中 {len(CHECKS) - ng}件が通った")
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
