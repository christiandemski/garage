#!/usr/bin/env python3
"""
MySkoda → Garage

Liest Kilometerstand sowie Inspektions- und Ölservice-Intervalle aus dem
MySkoda-Konto und überträgt sie an die Garage-API.

Nutzt die inoffizielle Open-Source-Bibliothek "myskoda"
(https://github.com/skodaconnect/myskoda) – nicht von Škoda unterstützt.

Umgebungsvariablen:
  SKODA_USER       E-Mail des MySkoda-Kontos
  SKODA_PASSWORD   Passwort des MySkoda-Kontos
  GARAGE_URL       z. B. https://garage.example.de   (ohne /api.php)
  GARAGE_TOKEN     API-Schlüssel aus Garage → Einstellungen

Lokal testen:
  pip install myskoda aiohttp
  SKODA_USER=… SKODA_PASSWORD=… GARAGE_URL=… GARAGE_TOKEN=… python3 tools/skoda_sync.py
"""
import asyncio
import json
import os
import sys
import urllib.request
import urllib.error

from aiohttp import ClientSession
from myskoda import MySkoda


def env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        sys.exit(f"Fehlende Umgebungsvariable: {name}")
    return value


def post_to_garage(payload: dict) -> dict:
    url = env("GARAGE_URL").rstrip("/") + "/api.php?action=skoda"
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode(),
        headers={
            "Authorization": "Bearer " + env("GARAGE_TOKEN"),
            "Content-Type": "application/json",
            "User-Agent": "garage-skoda-sync/1.0",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        sys.exit(f"Garage-API meldet Fehler {e.code}: {e.read().decode()[:300]}")


async def main() -> None:
    async with ClientSession() as session:
        skoda = MySkoda(session, mqtt_enabled=False)
        await skoda.connect(env("SKODA_USER"), env("SKODA_PASSWORD"))
        try:
            vins = await skoda.list_vehicle_vins()
            print(f"{len(vins)} Fahrzeug(e) im MySkoda-Konto")
            for vin in vins:
                payload = {"vin": vin}
                try:
                    rep = await skoda.get_maintenance_report(vin)
                    payload.update({
                        "mileage_km": rep.mileage_in_km,
                        "inspection_due_days": rep.inspection_due_in_days,
                        "inspection_due_km": rep.inspection_due_in_km,
                        "oil_due_days": rep.oil_service_due_in_days,
                        "oil_due_km": rep.oil_service_due_in_km,
                        "captured_at": rep.captured_at.isoformat() if rep.captured_at else None,
                    })
                except Exception as e:  # noqa: BLE001
                    print(f"  Wartungsbericht nicht verfügbar: {e}")
                if not payload.get("mileage_km"):
                    try:
                        health = await skoda.get_health(vin)
                        payload["mileage_km"] = health.mileage_in_km
                    except Exception as e:  # noqa: BLE001
                        print(f"  Kilometerstand nicht verfügbar: {e}")
                payload = {k: v for k, v in payload.items() if v is not None}
                masked = vin[:3] + "…" + vin[-4:]
                if len(payload) <= 1:
                    print(f"  {masked}: keine Daten – übersprungen")
                    continue
                result = post_to_garage(payload)
                print(f"  {masked}: {json.dumps(result.get('result', result), ensure_ascii=False)}")
        finally:
            await skoda.disconnect()


if __name__ == "__main__":
    asyncio.run(main())
