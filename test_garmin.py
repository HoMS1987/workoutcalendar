"""Machbarkeitstest: Bei Garmin Connect anmelden und die letzten Aktivitäten anzeigen.

Zugangsdaten kommen aus .env (siehe .env.example). Nach dem ersten erfolgreichen
Login liegen Tokens in .garminconnect/, weitere Läufe brauchen das Passwort dann nicht.
"""

import os
import sys
from pathlib import Path

from garminconnect import (
    Garmin,
    GarminConnectAuthenticationError,
    GarminConnectConnectionError,
    GarminConnectTooManyRequestsError,
)

PROJECT_DIR = Path(__file__).resolve().parent
TOKEN_DIR = PROJECT_DIR / ".garminconnect"


def load_env(path: Path) -> None:
    """Minimaler .env-Parser (KEY=VALUE), ohne zusätzliche Abhängigkeit."""
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def format_duration(seconds: float | None) -> str:
    if not seconds:
        return "-"
    minutes, sec = divmod(int(seconds), 60)
    hours, minutes = divmod(minutes, 60)
    return f"{hours}:{minutes:02d}:{sec:02d}"


def main() -> int:
    load_env(PROJECT_DIR / ".env")
    email = os.getenv("GARMIN_EMAIL") or None
    password = os.getenv("GARMIN_PASSWORD") or None

    TOKEN_DIR.mkdir(exist_ok=True)
    api = Garmin(email, password)

    try:
        api.login(str(TOKEN_DIR))
    except GarminConnectAuthenticationError as e:
        print(f"Login fehlgeschlagen (Authentifizierung): {e}")
        return 1
    except GarminConnectTooManyRequestsError as e:
        print(f"Login fehlgeschlagen (zu viele Anfragen / Rate-Limit): {e}")
        return 1
    except GarminConnectConnectionError as e:
        print(f"Login fehlgeschlagen (Verbindung / evtl. Cloudflare-Block): {e}")
        return 1

    print("Login erfolgreich.\n")

    activities = api.get_activities(0, 5)
    if not activities:
        print("Keine Aktivitäten gefunden.")
        return 0

    for a in activities:
        distance_km = (a.get("distance") or 0) / 1000
        print(
            f"{a.get('startTimeLocal', '?'):19}  "
            f"{a.get('activityType', {}).get('typeKey', '?'):18}  "
            f"{format_duration(a.get('duration')):>8}  "
            f"{distance_km:7.2f} km  "
            f"{a.get('activityName', '')}  (ID {a.get('activityId')})"
        )
    return 0


if __name__ == "__main__":
    sys.exit(main())
