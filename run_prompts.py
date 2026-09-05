#!/usr/bin/env python3
"""
run_prompts.py — Ejecuta, en orden, cada prompt definido en
LISTADO_PROMPTS_IMPLEMENTACION.md usando Claude Code en modo headless.
 
Uso:
    python3 run_prompts.py
    python3 run_prompts.py --start-from 5      # reanudar desde el prompt 5
    python3 run_prompts.py --dry-run           # solo muestra qué haría, no ejecuta nada
 
Requisitos:
    - Claude Code CLI instalado y autenticado (`claude --version` debe funcionar).
    - Este script, LISTADO_PROMPTS_IMPLEMENTACION.md, CLAUDE.md y allowed-tools.json
      viven en la raíz del proyecto Laravel (o se ejecuta desde ahí).
    - El proyecto es un repositorio git (para los commits automáticos por prompt).
"""
 
import argparse
import json
import os
import pathlib
import re
import subprocess
import sys
from datetime import datetime
 
PROMPTS_FILE = "LISTADO_PROMPTS_IMPLEMENTACION.md"
SETTINGS_FILE = "allowed-tools.json"
PROMPT_BLOCK_RE = re.compile(r"```prompt\n(.*?)\n```", re.DOTALL)
TITLE_RE = re.compile(r"^## Prompt (\d+) — (.+)$", re.MULTILINE)
MODEL_RE = re.compile(r"^- \*\*Modelo:\*\*\s*(\S+)", re.MULTILINE)
EFFORT_RE = re.compile(r"^- \*\*Esfuerzo del modelo:\*\*.*`(\w+)`", re.MULTILINE)
 
 
def extract_prompts(text: str):
    """Empareja cada bloque ```prompt``` con el título, modelo y esfuerzo que lo preceden."""
    titles = [(m.start(), m.group(1), m.group(2).strip()) for m in TITLE_RE.finditer(text)]
    blocks = [(m.start(), m.group(1).strip()) for m in PROMPT_BLOCK_RE.finditer(text)]
 
    prompts = []
    for block_pos, block_text in blocks:
        # el título correcto es el último que aparece antes de este bloque
        candidates = [t for t in titles if t[0] < block_pos]
        if not candidates:
            continue
        title_pos, number, title = candidates[-1]
 
        # el modelo y el esfuerzo están en el texto entre el título y el bloque de prompt
        metadata_chunk = text[title_pos:block_pos]
        model_match = MODEL_RE.search(metadata_chunk)
        effort_match = EFFORT_RE.search(metadata_chunk)
 
        model = model_match.group(1).strip().lower() if model_match else "sonnet"
        effort = effort_match.group(1).strip().lower() if effort_match else "medium"
 
        prompts.append({
            "number": int(number),
            "title": title,
            "text": block_text,
            "model": model,
            "effort": effort,
        })
 
    prompts.sort(key=lambda p: p["number"])
    return prompts
 
 
def run_claude(prompt_text: str, model: str, effort: str, settings_path: pathlib.Path, log_dir: pathlib.Path, index: int):
    cmd = [
        "claude",
        "-p", prompt_text,
        "--model", model,
        "--permission-mode", "dontAsk",
        "--settings", str(settings_path),
        "--output-format", "json",
    ]
 
    # El esfuerzo se fija vía variable de entorno (CLAUDE_CODE_EFFORT_LEVEL), no vía --effort:
    # esa variable tiene la precedencia más alta de las cinco fuentes posibles de esfuerzo en
    # Claude Code, así que fijarla explícitamente por invocación evita que un valor heredado
    # del shell (o la ausencia de uno) deje el esfuerzo real en un valor distinto al pedido.
    env = os.environ.copy()
    env["CLAUDE_CODE_EFFORT_LEVEL"] = effort
 
    # encoding/errors explícitos: en Windows, subprocess.run() decodifica los pipes con el
    # codepage local (cp1252) por defecto, y la salida de `claude` viene en UTF-8 — un solo
    # byte fuera de rango revienta la lectura del pipe entero y deja result.stdout en None.
    result = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", env=env)
 
    (log_dir / f"prompt-{index:02d}-stdout.json").write_text(result.stdout, encoding="utf-8")
    (log_dir / f"prompt-{index:02d}-stderr.log").write_text(result.stderr, encoding="utf-8")
 
    return result
 
 
def git_commit(message: str):
    subprocess.run(["git", "add", "-A"], capture_output=True)
    subprocess.run(["git", "commit", "-m", message], capture_output=True)
 
 
def main():
    parser = argparse.ArgumentParser(description="Ejecuta los prompts de implementación en orden con Claude Code.")
    parser.add_argument("--start-from", type=int, default=1, help="Número de prompt desde el cual reanudar (default: 1)")
    parser.add_argument("--stop-after", type=int, default=None, help="Número de prompt en el cual detenerse (inclusive)")
    parser.add_argument("--dry-run", action="store_true", help="Solo muestra el plan de ejecución, no llama a Claude Code")
    args = parser.parse_args()
 
    prompts_path = pathlib.Path(PROMPTS_FILE)
    if not prompts_path.exists():
        sys.exit(f"No se encontró {PROMPTS_FILE} en el directorio actual.")
 
    settings_path = pathlib.Path(SETTINGS_FILE)
    if not settings_path.exists() and not args.dry_run:
        sys.exit(f"No se encontró {SETTINGS_FILE} en el directorio actual. Créalo antes de continuar.")
 
    text = prompts_path.read_text(encoding="utf-8")
    prompts = extract_prompts(text)
 
    if not prompts:
        sys.exit("No se encontraron bloques ```prompt``` en el archivo. Revisa el formato del documento.")
 
    prompts = [p for p in prompts if p["number"] >= args.start_from]
    if args.stop_after is not None:
        prompts = [p for p in prompts if p["number"] <= args.stop_after]
 
    print(f"Se ejecutarán {len(prompts)} prompts, desde el {prompts[0]['number']} hasta el {prompts[-1]['number']}.\n")
 
    if args.dry_run:
        for p in prompts:
            print(f"  [{p['number']:02d}] {p['title']} — modelo={p['model']}, esfuerzo={p['effort']}")
        return
 
    log_dir = pathlib.Path(f"logs/build-{datetime.now():%Y%m%d-%H%M%S}")
    log_dir.mkdir(parents=True, exist_ok=True)
    print(f"Logs de esta corrida en: {log_dir}\n")
 
    for p in prompts:
        n, title, prompt_text = p["number"], p["title"], p["text"]
        model, effort = p["model"], p["effort"]
        print(f"=== Prompt {n:02d}: {title} [modelo={model}, esfuerzo={effort}] ===")
 
        result = run_claude(prompt_text, model, effort, settings_path, log_dir, n)
 
        if result.returncode != 0:
            print(f"❌ El prompt {n} terminó con código de salida {result.returncode}.")
            print(f"   Revisa {log_dir}/prompt-{n:02d}-stderr.log")
            print(f"   Para reanudar después de corregir: python3 run_prompts.py --start-from {n}")
            sys.exit(result.returncode)
 
        is_error = False
        result_text = ""
        try:
            payload = json.loads(result.stdout)
            is_error = bool(payload.get("is_error"))
            result_text = payload.get("result", "")
        except json.JSONDecodeError:
            print("⚠️  No se pudo interpretar la salida como JSON; revisa el log manualmente.")
 
        if is_error:
            print(f"❌ El prompt {n} reportó un error: {result_text[:500]}")
            print(f"   Para reanudar después de corregir: python3 run_prompts.py --start-from {n}")
            sys.exit(1)
 
        git_commit(f"Prompt {n:02d}: {title}")
        print(f"✅ Prompt {n:02d} completado y commiteado.\n")
 
    print("🎉 Todos los prompts solicitados se ejecutaron correctamente.")
 
 
if __name__ == "__main__":
    main()