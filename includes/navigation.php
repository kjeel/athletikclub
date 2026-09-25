<?php
/**
 * Athletikclub Steiermark – Zentrale Dashboard-Navigation
 * Ein Menü-Array für alle Rollen: Gruppen werden nur angezeigt, wenn mindestens ein Eintrag
 * sichtbar ist. Die Bedingungen entsprechen den Zugriffsprüfungen der Zielseiten.
 */

/** SVG-Innenleben der Menü-Icons (24×24, stroke). */
const NAV_ICONS = [
    'uebersicht' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    'heute'      => '<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>',
    'profil'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'kalender'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'dokument'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'kinder'     => '<circle cx="9" cy="7" r="3"/><circle cx="17" cy="10" r="2"/><path d="M3 21v-2a5 5 0 0 1 10 0v2"/><path d="M14 21v-1a3 3 0 0 1 6 0v1"/>',
    'karte'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
    'rechnung'   => '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 1 1V2l-1 1-3-2-3 2-3-2-3 2-3-2z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/>',
    'plan'       => '<path d="M6.5 6.5h11v11h-11z"/><path d="M2 12h4.5M17.5 12H22M4 8v8M20 8v8"/>',
    'puls'       => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'kurs'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 15h3"/>',
    'event'      => '<path d="M12 2l3 6 6 .9-4.5 4.3 1 6.3L12 16.5 6.5 19.5l1-6.3L3 8.9 9 8z"/>',
    'qr'         => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v7M14 20h3"/>',
    'personen'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'buch'       => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    'box'        => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/>',
    'uhr'        => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'medaille'   => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    'euro'       => '<path d="M18 7a7 7 0 1 0 0 10"/><line x1="4" y1="10" x2="14" y2="10"/><line x1="4" y1="14" x2="14" y2="14"/>',
    'balken'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'checkliste' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>',
    'ordner'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'haken'      => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'nachricht'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'gebaeude'   => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
    'vertrag'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/>',
    'hut'        => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
    'export'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'geld'       => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    'foerder'    => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/>',
    'antrag'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="M9 15h6"/>',
    'schloss'    => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'schild'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
    'stift'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    'glocke'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'blitz'      => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'zahnrad'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'monitor'    => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/><polyline points="6 12 9 9 12 11 17 7"/>',
    'datenschutz'=> '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="10" r="2"/><path d="M12 12v3"/>',
    'cockpit'    => '<path d="M12 2a10 10 0 1 0 10 10"/><path d="M12 12l6-6"/><circle cx="12" cy="12" r="1.5"/>',
];

/**
 * Menüstruktur für die angemeldete Person.
 * $z: Zähler für Badges (pending_anmeldungen, offene_bestaetigungen, meine_aufgaben, meine_ueberfaellig,
 *     abrechnungen_offen, unread_kontakt, ist_prae_empfaenger).
 */
function navigationMenue(array $z): array
{
    $t = isTrainer();
    $a = isAdmin();
    $gruppen = [
        'allgemein' => ['label' => 'Allgemein', 'items' => [
            ['Übersicht', '/dashboard/index.php', 'uebersicht', true, '#/dashboard/(index\.php)?$#'],
            ['Heute', '/dashboard/heute.php', 'heute', $t, '#/dashboard/heute#'],
            ['Kalender', '/dashboard/kalender.php', 'kalender', true],
            ['Mein Profil', '/dashboard/profil.php', 'profil', true],
            ['Kinder & Einwilligungen', '/dashboard/kinder.php', 'kinder', true],
            ['Meine Rechnungen', '/dashboard/meine-rechnungen.php', 'rechnung', true],
            ['Meine PRAE', '/dashboard/prae-meine.php', 'karte', !empty($z['ist_prae_empfaenger'])],
            ['Meine Pläne', '/dashboard/plaene.php', 'plan', !$t, '#/dashboard/(plaene|trainingsplan|ernaehrungsplan)#'],
            ['Meine Leistungswerte', '/dashboard/leistungsprofil.php', 'puls', !$t, '#/dashboard/(leistungsprofil|leistungstest)#'],
            ['Dokumente', '/dashboard/dokumente.php', 'dokument', true],
        ]],
        'sportbetrieb' => ['label' => 'Sportbetrieb', 'items' => [
            ['Kurse', '/dashboard/kurse.php', 'kurs', true, '#/dashboard/(kurse|kurs-detail|kurs-erstellen)#', $z['pending_anmeldungen'] ?? 0],
            ['Events', '/dashboard/events.php', 'event', darf('events.anzeigen')],
            ['Check-in', '/dashboard/checkin.php', 'qr', $t],
            ['Mitglieder', '/dashboard/mitglieder.php', 'personen', $t, '#/dashboard/(mitglieder|mitglied-detail)#'],
            ['Trainings- & Ernährungspläne', '/dashboard/plaene.php', 'plan', $t, '#/dashboard/(plaene|trainingsplan|ernaehrungsplan)#'],
            ['Übungsbibliothek', '/dashboard/uebungen.php', 'buch', $t],
            ['Leistungsdiagnostik', '/dashboard/leistungsdiagnostik.php', 'puls', $t, '#/dashboard/(leistungsdiagnostik|leistungstest|leistungsprofil|ld-tests)#'],
            ['Ressourcen & Material', '/dashboard/ressourcen.php', 'box', darf('ressourcen.anzeigen')],
        ]],
        'trainer' => ['label' => 'Trainer', 'items' => [
            ['Zeiterfassung', '/dashboard/zeiterfassung.php', 'uhr', $t, '#/dashboard/(zeiterfassung|einheit)#', $z['offene_bestaetigungen'] ?? 0],
            ['Qualifikationen', '/dashboard/qualifikationen.php', 'medaille', $t],
            ['Mein Umsatz', '/dashboard/umsatz.php', 'geld', $t, '#/dashboard/umsatz#'],
            ['Meine Statistik', '/dashboard/statistik.php', 'balken', $t && !$a],
            ['Trainer-Onboarding', '/dashboard/admin/onboarding.php', 'checkliste', darf('onboarding.anzeigen')],
        ]],
        'organisation' => ['label' => 'Organisation', 'items' => [
            ['Projekte', '/dashboard/projekte.php', 'ordner', $t, '#/dashboard/projekt#'],
            ['Aufgaben', '/dashboard/aufgaben.php', 'haken', $t, null, $z['meine_aufgaben'] ?? 0, empty($z['meine_ueberfaellig'])],
            ['Kommunikation', '/dashboard/kommunikation.php', 'nachricht', $t],
            ['Partner & CRM', '/dashboard/admin/partner.php', 'gebaeude', darf('partner.anzeigen')],
            ['Verträge', '/dashboard/admin/vertraege.php', 'vertrag', darf('vertraege.anzeigen')],
            ['Gemeinde-Kooperationen', '/dashboard/admin/kooperationen.php', 'personen', $a, '#/admin/kooperation#'],
            ['TBE-Gesamtkonzept', '/dashboard/admin/tbe.php', 'hut', $a, '#/admin/tbe#'],
            ['Dokumente & Exporte', '/dashboard/berichte.php', 'export', $t || darfEines('kinder.anzeigen', 'abrechnung.anzeigen', 'events.anzeigen', 'management.anzeigen', 'finanzen.anzeigen', 'foerderungen.anzeigen', 'rechnungen.anzeigen')],
        ]],
        'finanzen' => ['label' => 'Finanzen', 'items' => [
            ['Finanzen', '/dashboard/admin/finanzen.php', 'geld', darf('finanzen.anzeigen')],
            ['Rechnungen', '/dashboard/admin/rechnungen.php', 'rechnung', darf('rechnungen.anzeigen')],
            ['Trainerabrechnungen', '/dashboard/admin/trainerabrechnungen.php', 'checkliste', darfEines('abrechnung.anzeigen', 'abrechnung.bearbeiten', 'abrechnung.freigeben', 'abrechnung.abrechnen'), null, $z['abrechnungen_offen'] ?? 0],
            ['Kursabrechnungen', '/dashboard/admin/abrechnungen.php', 'dokument', $a],
            ['Umsatzübersicht', '/dashboard/admin/umsatz.php', 'euro', $a, '#/admin/umsatz#'],
            ['PRAE-Abrechnung', '/dashboard/admin/prae.php', 'karte', $a, '#/admin/prae#'],
        ]],
        'foerderungen' => ['label' => 'Förderungen', 'items' => [
            ['Fördermanagement', '/dashboard/admin/foerderungen.php', 'foerder', darf('foerderungen.anzeigen'), '#/admin/foerderung#'],
            ['Basisförderung SPORTUNION & Land', '/dashboard/admin/basisfoerderung.php', 'antrag', $a, '#/admin/basisfoerderung#'],
        ]],
        'administration' => ['label' => 'Administration', 'items' => [
            ['Nutzerverwaltung', '/dashboard/admin/nutzerverwaltung.php', 'personen', $a],
            ['Rollen & Rechte', '/dashboard/admin/rollen.php', 'schloss', darf('rollen.bearbeiten')],
            ['Seiteninhalte', '/dashboard/admin/inhalte.php', 'stift', $a, null, $z['unread_kontakt'] ?? 0],
            ['News', '/dashboard/admin/news.php', 'glocke', $a],
            ['Automatisierungen', '/dashboard/admin/automatisierungen.php', 'blitz', darf('automatisierungen.bearbeiten')],
            ['Einstellungen', '/dashboard/admin/einstellungen.php', 'zahnrad', darf('einstellungen.bearbeiten')],
            ['Datenschutz', '/dashboard/admin/datenschutz.php', 'datenschutz', darf('datenschutz.bearbeiten')],
            ['Systemstatus', '/dashboard/admin/systemstatus.php', 'monitor', darf('system.anzeigen')],
            ['Audit-Log', '/dashboard/admin/audit.php', 'schild', darf('audit.anzeigen')],
        ]],
        'management' => ['label' => 'Management', 'items' => [
            ['Management-Cockpit', '/dashboard/management.php', 'cockpit', darf('management.anzeigen')],
            ['Statistik & Auswertungen', '/dashboard/statistik.php', 'balken', $a],
        ]],
    ];
    foreach ($gruppen as $k => &$g) {
        $g['items'] = array_values(array_filter($g['items'], fn($i) => $i[3]));
        if (!$g['items']) unset($gruppen[$k]);
    }
    unset($g);
    return $gruppen;
}

/** Ist ein Menüeintrag für den aktuellen Pfad aktiv? */
function navigationAktiv(array $item, string $pfad): bool
{
    if (!empty($item[4])) return (bool)preg_match($item[4], $pfad);
    return strpos($pfad, preg_replace('/\.php$/', '', $item[1])) !== false;
}

/** Sidebar-HTML (Gruppen einklappbar; die Gruppe mit der aktiven Seite ist immer offen). */
function navigationHtml(array $gruppen, string $pfad): string
{
    $html = '';
    foreach ($gruppen as $key => $g) {
        $links = '';
        $offen = false;
        foreach ($g['items'] as $i) {
            $aktiv = navigationAktiv($i, $pfad);
            $offen = $offen || $aktiv;
            $badge = (int)($i[5] ?? 0);
            $links .= '<a href="' . APP_URL . $i[1] . '" class="sidebar-link' . ($aktiv ? ' active' : '') . '"' . ($aktiv ? ' aria-current="page"' : '') . '>'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . (NAV_ICONS[$i[2]] ?? '') . '</svg>'
                . e($i[0])
                . ($badge > 0 ? '<span class="sidebar-link-badge"' . (!empty($i[6]) ? ' style="background: var(--navy-light);"' : '') . '>' . ($badge > 99 ? '99+' : $badge) . '</span>' : '')
                . '</a>';
        }
        // Standard ohne gespeicherten Zustand: aktive Gruppe, „Allgemein“ und bei wenigen Gruppen alle offen
        $standard_offen = $offen || $key === 'allgemein' || count($gruppen) <= 3;
        $html .= '<details class="nav-gruppe" data-gruppe="' . $key . '"' . ($standard_offen ? ' open' : '') . ($offen ? ' data-aktiv="1"' : '') . '>'
            . '<summary class="sidebar-section-label nav-gruppe-kopf">' . e($g['label']) . '<svg class="nav-gruppe-pfeil" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg></summary>'
            . '<div class="nav-gruppe-links">' . $links . '</div></details>';
    }
    return $html;
}
