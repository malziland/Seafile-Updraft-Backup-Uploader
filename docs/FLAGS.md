# Feature-Flag-Register

Stand: v1.0.7, 2026-07-16.

## Release-Flags

**Keine.** Das Plugin enthält derzeit keine unfertigen Features hinter
Flags. Neue riskante oder unfertige Funktionalität bekommt gemäß
CHANGE-DELIVERY-Regeln ein Flag und einen Eintrag in dieser Tabelle:

| Name | Typ | Zweck | Owner | Default | Ablauf/Ticket | Entfernungs-Kriterium |
|---|---|---|---|---|---|---|
| — | — | — | — | — | — | — |

## Betriebs-Schalter (bewusst keine Flags)

Die folgenden Admin-Einstellungen wirken wie Operations-Schalter, sind aber
dauerhafte, dokumentierte Konfiguration — sie haben kein Ablaufdatum und
gehören nicht ins Flag-Register:

- `strict_verify` — Opt-in SHA1-Verifikation nach dem Upload (doppelte
  Bandbreite, daher standardmäßig aus).
- `notify` — E-Mail-Benachrichtigung: `never` / `error` / `always`.
- Aktivitätsprotokoll-Retention — 0 (aus) oder 7–365 Tage.

Kill-Switch für das Gesamtsystem ist die Plugin-Deaktivierung
(räumt Token-Transient und Retention-Cron auf, lässt Einstellungen stehen).

## Prüfregel

Dieses Register wird bei jedem KURZAUDIT auf abgelaufene Flags geprüft.
Ein abgelaufenes Flag gilt als Finding und blockiert die nächste
Release-Freigabe.
