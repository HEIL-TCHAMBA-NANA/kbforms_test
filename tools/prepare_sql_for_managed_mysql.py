#!/usr/bin/env python3
"""
Adapte le dump phpMyAdmin `kbforms.sql` pour un MySQL 8 managé strict
(Aiven, TiDB Cloud, PlanetScale…), où l'import direct échoue sur :

  * sql_require_primary_key : des CREATE TABLE sans PK inline (la PK arrive
    plus loin via ALTER) ;
  * une FK inline vers une table dont la clé unique n'existe pas encore
    (ordre des instructions du dump).

Transformation :
  1. en-tête : SET SESSION sql_require_primary_key=OFF; foreign_key_checks=OFF;
  2. les contraintes `CONSTRAINT ... FOREIGN KEY ...` écrites *dans* un
     CREATE TABLE sont retirées et ré-émises en `ALTER TABLE ... ADD ...`
     à la toute fin, quand toutes les tables et clés primaires existent.

Usage :  python3 tools/prepare_sql_for_managed_mysql.py kbforms.sql sortie.sql
"""
import re
import sys


def transform(src: str) -> str:
    create_re = re.compile(
        r'CREATE TABLE `(?P<name>[^`]+)` \((?P<body>.*?)\n\)(?P<tail>[^;]*);', re.S
    )
    deferred: list[tuple[str, str]] = []

    def process(m: re.Match) -> str:
        name, body, tail = m.group('name'), m.group('body'), m.group('tail')
        kept = []
        for line in body.split('\n'):
            s = line.strip().rstrip(',')
            if re.match(r'^CONSTRAINT `[^`]+` FOREIGN KEY', s, re.I):
                deferred.append((name, s))
            elif line.strip():
                kept.append(s)
        rebuilt = ',\n'.join('  ' + k for k in kept)
        return f"CREATE TABLE `{name}` (\n{rebuilt}\n){tail};"

    out = create_re.sub(process, src)

    if deferred:
        tail = ["", "--", "-- Clés étrangères différées (import MySQL 8 strict)", "--"]
        tail += [f"ALTER TABLE `{t}` ADD {c};" for t, c in deferred]
        out = out.rstrip() + "\n" + "\n".join(tail) + "\n"

    header = (
        "SET SESSION sql_require_primary_key=OFF;\n"
        "SET SESSION foreign_key_checks=OFF;\n"
    )
    return header + out, len(deferred)


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    src = open(sys.argv[1], encoding='utf-8').read()
    out, n = transform(src)
    open(sys.argv[2], 'w', encoding='utf-8').write(out)
    print(f"{sys.argv[2]} écrit — {n} clés étrangères reportées en fin de fichier")
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
