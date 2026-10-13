#!/usr/bin/env python3
"""HTTP-Regressionen des Kern-Endpunkts mit temporären Fixtures, ohne ILIAS-Daten."""

import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


def module(root, name):
    directory = root / "modules" / name
    directory.mkdir(parents=True)
    (directory / "module.json").write_text(json.dumps({
        "id": name, "label": "HTTP fixture", "handler_class": "ilEtsHttpResourceHandler",
        "handler_file": "handler.php", "tables": ["examressheet_settings"],
    }))
    (directory / "handler.php").write_text('<?php throw new RuntimeException("Handler must not load");')


def check_session_sql(plugin_root):
    """Die tatsächliche Kern-Abfrage gegen ein isoliertes In-Memory-Schema prüfen."""
    source = (plugin_root / "classes/class.ilExtendedTestSettingsModuleProxyGUI.php").read_text()
    match = re.search(r'"(SELECT a\.active_id.*?GROUP BY[^"\n]+)"', source, re.DOTALL)
    assert match, "Session-Abfrage nicht gefunden"
    query = match.group(1).replace("%s", "?")
    with sqlite3.connect(":memory:") as db:
        db.row_factory = sqlite3.Row
        db.executescript("""
            CREATE TABLE tst_tests (test_id INTEGER, obj_fi INTEGER);
            CREATE TABLE tst_active (active_id INTEGER, test_fi INTEGER, user_fi INTEGER,
                submitted INTEGER, tries INTEGER, last_started_pass INTEGER, last_finished_pass INTEGER);
            CREATE TABLE tst_times (active_fi INTEGER, pass INTEGER, started TEXT);
            INSERT INTO tst_tests VALUES (999, 123), (888, 456);
            INSERT INTO tst_active VALUES (7, 999, 6, 0, 1, 1, 0),
                (8, 999, 99, 0, 1, 1, 0), (9, 888, 6, 0, 1, 1, 0);
            INSERT INTO tst_times VALUES (7, 0, '2026-01-01 10:00:00'),
                (7, 1, '2026-01-01 11:00:00'), (7, 1, '2026-01-01 11:05:00'),
                (8, 1, '2026-01-01 10:00:00'), (9, 1, '2026-01-01 10:00:00');
        """)
        row = db.execute(query, (123, 6, 0)).fetchone()
        assert row["active_id"] == 7 and row["tries"] == 1
        assert row["pass_started"] == "2026-01-01 11:00:00"
        assert db.execute(query, (999, 6, 0)).fetchone() is None, "obj_id und test_id verwechselt"
        assert db.execute(query, (123, 777, 0)).fetchone() is None, "Fremder Benutzer ausgewählt"
        db.execute("UPDATE tst_active SET submitted = 1 WHERE active_id = 7")
        assert db.execute(query, (123, 6, 0)).fetchone() is None, "Abgegebener Durchlauf ausgewählt"
        db.execute("UPDATE tst_active SET submitted = 0, tries = 2 WHERE active_id = 7")
        assert db.execute(query, (123, 6, 0)).fetchone() is None, "Nicht gestarteter Durchlauf ausgewählt"
    print("OK: tatsächliche Session-SQL-Abfrage mit Benutzer-, Test-, Abgabe- und Durchlauf-Isolation geprüft.")


def main():
    router = Path(__file__).resolve().with_name("ilEtsResourceEndpoint.php")
    check_session_sql(router.parents[2])
    with tempfile.TemporaryDirectory(prefix="ets-resource-http-") as temporary:
        root = Path(temporary)
        module(root / "plugin", "resourceowner")
        module(root / "ambiguous", "resourceone")
        module(root / "ambiguous", "resourcetwo")
        directory = root / "plugins/ExtendedTestSettings/resourcesheets"
        directory.mkdir(parents=True)
        pdf = b"%PDF-1.7\nETS_HTTP_FIXTURE_ONLY\n"
        html = b"<p>ETS_HTTP_FIXTURE_ONLY</p>"
        stem = "123_" + "a" * 32
        (directory / (stem + ".pdf")).write_bytes(pdf)
        (directory / (stem + ".html")).write_bytes(html)

        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        environment = os.environ.copy()
        environment["ETS_RESOURCE_FIXTURE_DIR"] = str(root)
        with (root / "server.log").open("w+") as log:
            server = subprocess.Popen([
                os.environ.get("PHP_BIN", "php8.2"), "-S", f"127.0.0.1:{port}",
                "-t", str(root), str(router),
            ], env=environment, cwd=root, stdout=log, stderr=subprocess.STDOUT)
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        raise RuntimeError("PHP-Testserver wurde vorzeitig beendet")
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.05)
                else:
                    raise RuntimeError("PHP-Testserver nicht erreichbar")

                cases = [(mode, expected, {}, {}) for mode, expected in [
                    ("allowed", 200), ("disabled", 403), ("missing", 403),
                    ("ambiguous", 403), ("read_revoked", 403), ("submitted", 403),
                    ("finished", 403), ("not_started", 403), ("expired", 403),
                    ("extra_time", 200), ("end_reached", 403), ("offline", 403),
                    ("not_test", 404), ("db_error", 403), ("teacher", 200),
                    ("teacher_disabled", 403), ("html", 200),
                ]]
                cases += [
                    ("allowed", 403, {"token": "b" * 32}, {}),
                    ("disabled", 403, {"module_id": "some_active_module"}, {}),
                    ("html", 403, {}, {"Sec-Fetch-Dest": "document"}),
                ]
                for mode, expected, query_extra, headers in cases:
                    query = {"cmd": "serveResourceFile", "ref_id": "77",
                             "token": "a" * 32, "scenario": mode}
                    query.update(query_extra)
                    request = Request(f"http://127.0.0.1:{port}/?{urlencode(query)}", headers=headers)
                    try:
                        response = urlopen(request, timeout=5)
                    except HTTPError as error:
                        response = error
                    with response:
                        body = response.read()
                        if response.status != expected:
                            raise AssertionError(f"{mode}: HTTP {response.status}, erwartet {expected}; {body!r}")
                        if expected == 200:
                            assert body == (html if mode == "html" else pdf), (mode, body)
                            assert "no-store" in response.headers.get("Cache-Control", ""), mode
                            assert "sandbox" in response.headers.get("Content-Security-Policy", ""), mode
                            assert response.headers.get("X-Frame-Options") == "DENY", mode
                            if mode == "html":
                                assert response.headers.get("Content-Disposition", "").startswith("attachment"), mode
                        else:
                            assert b"ETS_HTTP_FIXTURE_ONLY" not in body, mode
                print(f"OK: {len(cases)} isolierte HTTP-Fälle; Status, Dateiinhalte, Modulsperre und Sicherheitsheader geprüft.")
            except Exception:
                log.flush()
                log.seek(0)
                print(log.read())
                raise
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)


if __name__ == "__main__":
    main()
