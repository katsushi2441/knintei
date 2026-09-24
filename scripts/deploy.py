#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""knintei を heteml（kurage.exbridge.jp）へ上げる。

  /usr/bin/python3 scripts/deploy.py           # PHP・OGP・SQLite
  /usr/bin/python3 scripts/deploy.py --php     # PHP と OGP だけ（SQLite を送らない）

**FTP は1接続にまとめる。** 短時間に接続を重ねると、うちのIPが全ポートで
15〜20分遮断される（[[reference_heteml_ftp_block]]）。確認は HTTPS で行う。

置き場所:
  /web/kurage_exbridge_jp/knintei.php
  /web/kurage_exbridge_jp/knintei_data/…   … .htaccess で直読み禁止
  /web/kurage_exbridge_jp/images/ogp/knintei.png
"""
import ftplib
import os
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = "https://kurage.exbridge.jp"
REMOTE = "/web/kurage_exbridge_jp"
PHP_ONLY = "--php" in sys.argv
DATA = os.path.join(ROOT, "php", "knintei_data")

FILES = [(f"{ROOT}/php/knintei.php", f"{REMOTE}/knintei.php"),
         (f"{DATA}/.htaccess", f"{REMOTE}/knintei_data/.htaccess"),
         (f"{ROOT}/outputs/knintei_1200x630.png", f"{REMOTE}/images/ogp/knintei.png")]
if not PHP_ONLY:
    FILES.append((f"{DATA}/knintei.sqlite", f"{REMOTE}/knintei_data/knintei.sqlite"))


def env():
    for line in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in line and not line.startswith("#"):
            k, v = line.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main() -> int:
    env()
    total = sum(os.path.getsize(l) for l, _ in FILES)
    print(f"{len(FILES)}ファイル / {total/1e6:.1f}MB を1接続で送ります")
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=1800)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    cur = None
    for local, remote in FILES:
        d = os.path.dirname(remote)
        if d != cur:
            try:
                f.cwd(d)
            except ftplib.error_perm:
                f.mkd(d); f.cwd(d)
            cur = d
        with open(local, "rb") as fh:
            f.storbinary("STOR " + os.path.basename(remote), fh, blocksize=1 << 18)
        print(f"  {remote}  {os.path.getsize(local)/1024:.0f}KB", flush=True)
    f.quit()

    for path in ("/knintei.php/", "/knintei.php/kubun", "/knintei.php/nagare",
                 "/knintei.php/item/1-3", "/knintei.php/group/4",
                 "/knintei.php/city/%E6%84%9B%E7%9F%A5%E7%9C%8C/%E5%90%8D%E5%8F%A4%E5%B1%8B%E5%B8%82%E4%B8%AD%E5%8C%BA",
                 "/knintei.php/pref/%E6%84%9B%E7%9F%A5%E7%9C%8C", "/knintei.php/cities",
                 "/knintei.php/about", "/knintei.php/sitemap.xml", "/knintei.php/llms.txt",
                 "/images/ogp/knintei.png"):
        try:
            with urllib.request.urlopen(urllib.request.Request(BASE + path, headers={"User-Agent": "knintei-deploy/1.0"}), timeout=90) as r:
                print(f"  {r.status} {len(r.read(400))}B+ {BASE}{path}")
        except Exception as e:
            print(f"  ! {BASE}{path}: {e}")
    try:
        with urllib.request.urlopen(BASE + "/knintei_data/knintei.sqlite", timeout=60) as r:
            print(f"  ! SQLite が直接読めてしまう: {r.status}")
    except Exception as e:
        print(f"  {getattr(e, 'code', '?')} SQLite の直読みは拒否されている（想定どおり）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
