"""Terminal and saved summaries derived from actual run outcomes."""
from datetime import datetime


def summarize(metadata, deltas):
    stations = metadata.get('stations', [])
    researched = [s for s in stations if s.get('research_started') or s.get('status') == 'processed']
    # Legacy reports lacked stage markers; their geocoding failures are explicit.
    geographic = [s for s in stations if s.get('status') in ('unresolved', 'boundary') and
                  (s.get('stage') in ('geography', 'prepare') or 'stage' not in s and
                   (not s.get('reason') or s['reason'] == 'Adresse nicht eindeutig geokodierbar'))]
    return {
        'api_stations_researched': sum(s.get('wache_id') is not None for s in researched),
        'catalog_hints_researched': sum(s.get('wache_id') is None for s in researched),
        'outside_package': sum(s.get('status') == 'outside' for s in stations),
        'geography_unresolved': len(geographic),
        'vehicle_evidence': sum(s.get('active_count', 0) for s in stations),
        'change_proposals': len(deltas),
        'api_writes': metadata.get('api_writes', 0),
        'pending': len(metadata.get('pending', [])),
        'stations_with_evidence': sum(s.get('active_count', 0) > 0 for s in stations),
        'researched_without_evidence': sum(not s.get('active_count') for s in researched),
        'callsign_conflicts': sum(s.get('reason') == 'Rufname an anderer Wache' for s in metadata.get('unresolved', [])),
    }


def render_summary(metadata, counts):
    area = (metadata.get('package') or {}).get('region') or metadata.get('state', '')
    rows = [
        ('Vorhandene API-Wachen recherchiert', counts['api_stations_researched']),
        ('Zusätzliche Kataloghinweise recherchiert', counts['catalog_hints_researched']),
        ('Einträge außerhalb des Pakets ausgeschlossen', counts['outside_package']),
        ('Einträge mit ungeklärter geografischer Zuordnung', counts['geography_unresolved']),
        ('Gefundene Fahrzeugbelege / Änderungsvorschläge', f"{counts['vehicle_evidence']} / {counts['change_proposals']}"),
        ('API-Schreibaktionen', counts['api_writes']),
    ]
    lines = [f"Auswertung: {area} — {metadata.get('status', 'unbekannt')}", '',
             '| Ergebnis | Anzahl |', '| --- | ---: |']
    lines += [f'| {label} | {value} |' for label, value in rows]
    lines += ['', f"Wachen mit Belegen: {counts['stations_with_evidence']}; recherchiert ohne Belege: {counts['researched_without_evidence']}; Rufnamenkonflikte: {counts['callsign_conflicts']}; noch ausstehend: {counts['pending']}.",
              'Fahrzeugbelege sind Kandidaten, keine Bestätigung eines vollständigen Fuhrparks. Änderungsvorschläge sind nicht automatisch schreibfähig.']
    if metadata.get('finished_at') and metadata.get('generated_at'):
        seconds = (datetime.fromisoformat(metadata['finished_at']) - datetime.fromisoformat(metadata['generated_at'])).total_seconds()
        lines.append(f'Recherchelauf: {seconds:.0f} Sekunden (ohne vorgelagertes Laden des API-Bestands).')
    return '\n'.join(lines) + '\n'
