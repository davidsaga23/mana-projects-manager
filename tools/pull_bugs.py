"""
Pull Anima's bug reports into bug-inbox/ so Claude can work through them, and
close or flag them afterwards. The /bugs command (.claude/skills/bugs) drives it.

    python tools/pull_bugs.py                         pull every open report
    python tools/pull_bugs.py --all                   ...closed ones too
    python tools/pull_bugs.py close 12 fixed --note "PR #61"
    python tools/pull_bugs.py close 12 wontfix --note "Works as designed"
    python tools/pull_bugs.py close 12 open
    python tools/pull_bugs.py flag 12 --note "Which project were you on?"

THE KEY is the 'bug_export_key' in the server's config.php. It is read from the
ANIMA_BUG_KEY environment variable, or else from tools/.bug_key (one line,
gitignored). It travels in a header, never in the URL.

THE SITE defaults to the live one; ANIMA_URL points it elsewhere.

VIDEOS: Claude can read images but cannot watch a video. When ffmpeg is on the
PATH, a few still frames are cut from each one into frames/ beside it, so there
is at least something to look at. Either way the report is marked as having a
video, and bugs.php has already flagged it for David to describe.

Standard library only, so there is nothing to install.
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INBOX = ROOT / "bug-inbox"
KEY_FILE = Path(__file__).resolve().parent / ".bug_key"
SITE = os.environ.get("ANIMA_URL", "https://mana.davidsaga.com").rstrip("/")
FRAMES_PER_VIDEO = 6


def key() -> str:
    k = os.environ.get("ANIMA_BUG_KEY", "").strip()
    if not k and KEY_FILE.exists():
        k = KEY_FILE.read_text(encoding="utf-8").strip()
    if not k:
        sys.exit("No bug export key. Put it in tools/.bug_key or the ANIMA_BUG_KEY "
                 "environment variable (it is 'bug_export_key' in the server's config.php).")
    return k


def request(path: str, data: dict | None = None) -> bytes:
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(f"{SITE}/{path}", data=body, headers={
        "X-Bug-Key": key(),
        # Some shared hosts refuse Python's default user agent outright.
        "User-Agent": "anima-pull-bugs/1.0",
    })
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.read()
    except urllib.error.HTTPError as e:
        try:
            msg = json.loads(e.read()).get("error", "")
        except Exception:
            msg = ""
        sys.exit(f"{SITE}/{path} answered {e.code}. {msg}".strip())


def safe_name(name: str) -> str:
    keep = "".join(c if c.isalnum() or c in "._- " else "_" for c in name).strip()
    return keep or "file"


def cut_frames(video: Path, out: Path) -> int:
    """A few evenly spaced stills from a video. Returns how many were written."""
    if not shutil.which("ffmpeg") or not shutil.which("ffprobe"):
        return 0
    try:
        dur = float(subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration",
             "-of", "default=nw=1:nk=1", str(video)],
            capture_output=True, text=True, timeout=60).stdout.strip() or 0)
    except (ValueError, subprocess.SubprocessError):
        return 0
    if dur <= 0:
        return 0
    out.mkdir(exist_ok=True)
    n = 0
    for i in range(FRAMES_PER_VIDEO):
        t = dur * (i + 0.5) / FRAMES_PER_VIDEO
        frame = out / f"{video.stem}_{i + 1:02d}_{t:05.1f}s.jpg"
        subprocess.run(["ffmpeg", "-v", "error", "-y", "-ss", f"{t:.2f}", "-i", str(video),
                        "-frames:v", "1", "-vf", "scale='min(1280,iw)':-2", str(frame)],
                       capture_output=True, timeout=60)
        n += frame.exists()
    return n


def pull(include_closed: bool) -> None:
    data = json.loads(request("bugs_export.php" + ("?status=all" if include_closed else "")))
    # The inbox mirrors the server: a report closed since the last pull should
    # not linger here looking open. Only ever this one folder.
    if INBOX.exists():
        shutil.rmtree(INBOX)
    INBOX.mkdir()

    waiting, ready = [], []
    for b in data["bugs"]:
        d = INBOX / f"bug-{b['id']:04d}"
        d.mkdir()
        frames = 0
        for f in b["files"]:
            target = d / f"{f['id']}_{safe_name(f['name'])}"
            target.write_bytes(request(f"bugs_export.php?file={f['id']}"))
            f["local"] = str(target.relative_to(ROOT)).replace("\\", "/")
            if f["kind"] == "video":
                frames += cut_frames(target, d / "frames")
        b["video_frames"] = frames
        (d / "report.json").write_text(json.dumps(b, indent=2, ensure_ascii=False), encoding="utf-8")
        (waiting if b["needs_info"] and not b["admin_notes"] else ready).append(b)

    (INBOX / "index.json").write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")

    print(f"{len(data['bugs'])} report(s) pulled into bug-inbox/ from {SITE}")
    if not data.get("flags_available"):
        print("  (database update 064 is not applied yet: no video flags or notes)")
    for b in ready:
        tag = " [video]" if b["has_video"] else ""
        print(f"  READY   #{b['id']} {b['section']}{tag}: {b['description'][:70]!r}")
    for b in waiting:
        print(f"  WAITING #{b['id']} {b['section']}: {b['flag_reason']}")


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--all", action="store_true", help="include closed reports")
    sub = p.add_subparsers(dest="cmd")
    c = sub.add_parser("close", help="mark a report fixed, won't fix, or open again")
    c.add_argument("bug_id", type=int)
    c.add_argument("action", choices=["fixed", "wontfix", "open"])
    c.add_argument("--note", default="", help="e.g. the PR that fixed it")
    f = sub.add_parser("flag", help="ask David for more detail on a report")
    f.add_argument("bug_id", type=int)
    f.add_argument("--note", required=True, help="what David needs to describe")
    a = p.parse_args()

    if a.cmd == "close":
        print(request("bugs_export.php", {"bug_id": a.bug_id, "action": a.action, "note": a.note}).decode())
    elif a.cmd == "flag":
        print(request("bugs_export.php", {"bug_id": a.bug_id, "action": "flag", "note": a.note}).decode())
    else:
        pull(a.all)


if __name__ == "__main__":
    main()
