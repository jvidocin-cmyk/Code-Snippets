<?php

/**
 * Coworking réservation Données
 */
add_shortcode('coworking_resa', function() {
    if (!is_singular('offre-coworking')) {
        return "<p>Ce module ne peut être affiché que sur une offre coworking.</p>";
    }

    $post_id = get_the_ID();

    $data = [
        "post_id" => $post_id,
        "title" => get_the_title($post_id),
        "formules" => get_field('formules_disponibles', $post_id) ?: [],
        "capacite" => intval(get_field('capacite_max', $post_id)),
        "prix" => [
            "demi_journee" => get_field('prix_demi_journee', $post_id),
            "journee" => get_field('prix_journee', $post_id),
            "semaine" => get_field('prix_semaine', $post_id),
        ],
        "blocked_dates" => json_decode(get_field('reservations_json', $post_id) ?: "[]", true)
    ];

    return '<div id="coworking-app"></div>
            <script>window.COWORKING_DATA = '.json_encode($data).';</script>';
});

/**
 * Coworking Availability System
 */
/**
 * Coworking Availability System
 * Version: 2.0 - Optimisé pour stockage propre
 */

use WP_REST_Request;
use WP_REST_Response;

/* ------------------------------------------------------------
   HELPERS
------------------------------------------------------------ */

/** Date du jour au format YYYY-MM-DD (avec timezone WordPress) */
function coworking_today_date() {
    return date_i18n('Y-m-d', current_time('timestamp'));
}

/** Compter les réservations existantes pour un jour */
function count_reservations_for_date($date, $reservations) {
    $count = 0;

    foreach ($reservations as $r) {
        $start = $r['start'] ?? '';
        $end   = $r['end'] ?? '';
        $qty   = (int) ($r['quantity'] ?? 1);

        if ($date >= $start && $date <= $end) {
            $count += $qty;
        }
    }

    return $count;
}

/* ------------------------------------------------------------
   AVAILABILITY — VERSION AVANCÉE (UTILISABLE EN INTERNE)
------------------------------------------------------------ */

/**
 * Renvoie le tableau complet de disponibilité pour un mois donné.
 * → Utilisé par l'endpoint public ET par calculate-price
 */
/**
 * Renvoie le tableau complet de disponibilité pour un mois donné.
 * CORRIGÉ : Intègre les "Locks" (paniers en cours) pour éviter l'affichage "Low" quand c'est réellement complet.
 */
function coworking_get_availability_for_month(int $offre_id, string $month) {

    // 1. Capacité
    $capacity = (int) get_field('capacite_max', $offre_id);
    if ($capacity <= 0) $capacity = 7; // Attention : Assurez-vous que c'est cohérent avec vos bureaux

    // 2. Réservations confirmées (JSON)
    $reservations_json = get_field('reservations_json', $offre_id) ?: '[]';
    $reservations = json_decode($reservations_json, true);
    if (!is_array($reservations)) $reservations = [];

    // --- FIX DÉBUT : AJOUT DES LOCKS (PANIERS EN COURS) ---
    // On récupère les locks temporaires pour les traiter comme des réservations
    $locks = get_transient('cw_locks_' . $offre_id);
    if (is_array($locks)) {
        $now = time();
        foreach ($locks as $lock) {
            // Si le lock est encore valide (pas expiré)
            if (isset($lock['expires_at']) && $lock['expires_at'] > $now) {
                // On l'ajoute virtuellement à la liste des réservations pour le calcul
                $reservations[] = [
                    'start' => $lock['start'],
                    'end'   => $lock['end'],
                    'quantity' => $lock['quantity'] ?? 1
                ];
            }
        }
    }
    // --- FIX FIN ---

    // 3. Dates bloquées manuelles
    $manual_raw = get_field('dates_indisponibles_manuel', $offre_id) ?: '';
    $manual_block = array_filter(array_map('trim', explode("\n", $manual_raw)));

    // 4. Parsing du mois
    $p = explode('-', $month);
    if (count($p) !== 2) return [];
    $year = (int) $p[0];
    $month_num = (int) $p[1];
    if ($month_num < 1 || $month_num > 12) return [];

    $days = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    $today = coworking_today_date();

    $availability = [];

    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month_num, $d);

        $is_past = ($date <= $today);
        $is_manual = in_array($date, $manual_block);

        // Compte les réservations + les locks
        $reserved = count_reservations_for_date($date, $reservations);
        $slots = max(0, $capacity - $reserved);

        $status = 'available';
        
        if ($is_past || $is_manual) {
            $status = 'unavailable';
        } elseif ($slots == 0) {
            $status = 'full'; // Passera ici car reserved == capacity grâce aux locks
        } elseif ($slots <= 2) {
            $status = 'low';
        }

        $availability[$date] = [
            'date' => $date,
            'status' => $status,
            'slots' => $slots,
            'capacity' => $capacity,
            'is_past' => $is_past
        ];
    }

    return $availability;
}

/* ------------------------------------------------------------
   REST API — AVAILABILITY
------------------------------------------------------------ */

add_action('rest_api_init', function() {
    register_rest_route('coworking/v1', '/availability/(?P<offre_id>\d+)', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $req) {

            $offre_id = (int) $req->get_param('offre_id');
            $month = $req->get_param('month') ?: date('Y-m');

            if (!$offre_id) {
                return new WP_REST_Response(['success' => false, 'message' => 'Offre manquante'], 400);
            }

            $availability = coworking_get_availability_for_month($offre_id, $month);

            return new WP_REST_Response([
                'success' => true,
                'offre_id' => $offre_id,
                'month' => $month,
                'availability' => $availability,
                'prix' => [
                    'journee' => (float) get_field('prix_journee', $offre_id),
                    'semaine' => (float) get_field('prix_semaine', $offre_id),
                    'mois'    => (float) get_field('prix_mois', $offre_id),
                ],
            ], 200);
        }
    ]);
});

/* ------------------------------------------------------------
   REST API — CALCULATE PRICE (validations avancées)
------------------------------------------------------------ */

add_action('rest_api_init', function() {
    register_rest_route('coworking/v1', '/calculate-price', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $req) {

            $p = $req->get_json_params();

            $offre_id = (int) ($p['offre_id'] ?? 0);
            $formule  = sanitize_text_field($p['formule'] ?? '');
            $start    = sanitize_text_field($p['start_date'] ?? '');
            $end      = sanitize_text_field($p['end_date'] ?? $start);

            if (!$offre_id || !$formule || !$start) {
                return new WP_REST_Response(['success' => false, 'message' => 'Paramètres manquants'], 400);
            }

            // J+1
            if ($start <= coworking_today_date()) {
                return new WP_REST_Response(['success' => false, 'message' => 'La date doit être au minimum demain (J+1).'], 400);
            }

            $ts_start = strtotime($start);
            $ts_end   = strtotime($end);
            if ($ts_end < $ts_start) {
                return new WP_REST_Response(['success' => false, 'message' => 'Période invalide'], 400);
            }

            // Déterminer les mois nécessaires
            $months = [];
            $cur = strtotime(date('Y-m-01', $ts_start));
            $last = strtotime(date('Y-m-01', $ts_end));
            while ($cur <= $last) {
                $months[] = date('Y-m', $cur);
                $cur = strtotime('+1 month', $cur);
            }

            // Charger l'availability totale
            $all = [];
            foreach ($months as $m) {
                $a = coworking_get_availability_for_month($offre_id, $m);
                $all = array_merge($all, $a);
            }

            // Vérification de chaque jour
            for ($t = $ts_start; $t <= $ts_end; $t += 86400) {
                $d = date('Y-m-d', $t);

                if (!isset($all[$d])) {
                    return new WP_REST_Response(['success' => false, 'message' => "Jour hors disponibilité : $d"], 400);
                }

                if ($all[$d]['slots'] <= 0) {
                    return new WP_REST_Response(['success' => false, 'message' => "Jour complet : $d"], 400);
                }
            }

            // PRIX (depuis les champs ACF de l'offre - toujours à jour)
            $day   = (float) get_field('prix_journee', $offre_id);
            $week  = (float) get_field('prix_semaine', $offre_id);
            $month = (float) get_field('prix_mois', $offre_id);

            switch ($formule) {
                case 'journee': $price = $day; break;
                case 'semaine': $price = $week ?: ($day * 5); break;
                case 'mois':    $price = $month ?: ($week * 4); break;
                default:        $price = 0;
            }

            return new WP_REST_Response([
                'success' => true,
                'price' => $price,
                'range' => [$start, $end]
            ], 200);
        }
    ]);
});

/**
 * Calendrier Coworking
 */
/**
 * Calendrier Coworking V6 - FIXED Visual Consistency
 * - Dates FULL = toujours grisées (comme salle de réunion)
 * - Dates LOW = point jaune mais cliquable
 * - Dates disponibles = bleues
 */

add_shortcode('coworking_calendar', 'render_coworking_calendar_v6');

function render_coworking_calendar_v6() {
    $offre_id = get_the_ID();
    $price_day = (float) get_field('prix_journee', $offre_id);
    $price_week = (float) get_field('prix_semaine', $offre_id);
    $price_month = (float) get_field('prix_mois', $offre_id);
    
    ob_start();
    ?>

<style>
/* --- STYLES CSS (Identiques + fix pour cohérence visuelle) --- */
:root {
    --cw-primary: #276890;
    --cw-secondary: #5AB7E2;
    --cw-bg-range: #e0f2fe;
    --cw-border-range: #b3dff5;
    --cw-unavailable: #f3f4f6;
    --cw-text-mute: #9ca3af;
}

.coworking-calendar-container {
    max-width: 1200px; margin: 0 auto; display: none; opacity: 0;
    transform: translateY(20px); transition: opacity 0.4s ease, transform 0.4s ease;
    min-height: 400px;
}
.coworking-calendar-container.active { display: block; opacity: 1; transform: translateY(0); }

.calendar-card { background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 4px 20px rgba(40,98,145,.08); margin-top: 20px; }

/* Header */
.calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 2px solid #f0f0f0; }
.calendar-title { font-size: 22px; font-weight: 700; color: #344256; margin:0; }
.calendar-nav { display: flex; gap: 10px; align-items: center; }
.month-display { font-size: 16px; font-weight: 600; color: var(--cw-primary); min-width: 160px; text-align: center; text-transform: capitalize; user-select: none; }
.nav-btn { width: 40px; height: 40px; border: 2px solid #e5e7eb; background: #fff; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .15s; color: #65758b; font-size: 18px; }
.nav-btn:hover { border-color: var(--cw-primary); background: #f0f7fb; color: var(--cw-primary); }

/* Legend */
.calendar-legend { display: flex; gap: 18px; margin-bottom: 18px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #65758b; }
.legend-dot { width: 12px; height: 12px; border-radius: 3px; }
.legend-dot.available { background: #f0f9ff; border: 1px solid #d1e9f6; }
.legend-dot.unavailable { background: var(--cw-unavailable); }
.legend-dot.selected { background: var(--cw-primary); }
.legend-dot.in-range { background: var(--cw-bg-range); }

/* Grid */
.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; min-height: 300px; position: relative; }
.day-header { text-align: center; padding: 10px 0; font-size: 12px; font-weight: 700; color: #65758b; text-transform: uppercase; }

.calendar-day { 
    aspect-ratio: 1; display: flex; align-items: center; justify-content: center; 
    font-size: 15px; font-weight: 500; border-radius: 10px; cursor: pointer; position: relative; 
    color: #344256; background: #fff; border: 2px solid transparent; transition: transform .1s, background-color .15s; 
    user-select: none;
}

/* States */
.calendar-day.available { background: #f0f9ff; border-color: #d1e9f6; }
.calendar-day.available:hover { background: var(--cw-secondary); color: #fff; transform: scale(1.05); border-color: var(--cw-secondary); z-index: 2; }

/* ⚠️ FIX: FULL et UNAVAILABLE = même style grisé */
.calendar-day.unavailable,
.calendar-day.full { 
    background: var(--cw-unavailable); 
    color: #d1d5db; 
    cursor: not-allowed; 
    pointer-events: none; 
}

.calendar-day.past { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

/* Status Logic - LOW reste cliquable avec point jaune */
.calendar-day.low::after { 
    content: ''; 
    position: absolute; 
    bottom: 6px; 
    left: 50%; 
    transform: translateX(-50%); 
    width: 5px; 
    height: 5px; 
    background: #f59e0b; 
    border-radius: 50%; 
}

/* Selection */
.calendar-day.selected { background: var(--cw-primary) !important; color: #fff !important; border-color: var(--cw-primary) !important; font-weight: 700; z-index: 3; box-shadow: 0 4px 10px rgba(39,104,144,0.3); }
.calendar-day.in-range { background: var(--cw-bg-range); border-color: var(--cw-border-range); color: var(--cw-primary); }
.calendar-day.range-start { border-radius: 10px 0 0 10px; }
.calendar-day.range-end { border-radius: 0 10px 10px 0; }

/* Loading State Overlay */
.grid-loading { position: absolute; inset: 0; background: rgba(255,255,255,0.8); display: flex; align-items: center; justify-content: center; z-index: 10; font-weight: 600; color: var(--cw-primary); backdrop-filter: blur(2px); border-radius: 12px; }

/* Summary Footer */
.selection-summary { background: linear-gradient(135deg, var(--cw-primary) 0%, var(--cw-secondary) 100%); border-radius: 12px; padding: 20px; margin-top: 24px; color: #fff; display: none; }
.selection-summary.active { display: block; animation: slideInUp 0.3s ease-out; }
@keyframes slideInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.summary-content { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
.summary-col { display: flex; flex-direction: column; }
.summary-label { font-size: 11px; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.summary-value { font-size: 16px; font-weight: 700; }
.price-amount { font-size: 28px; font-weight: 800; line-height: 1; }

.reserve-btn { background: #fff; color: var(--cw-primary); border: none; padding: 14px 28px; border-radius: 10px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all .2s; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.reserve-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
.reserve-btn:disabled { opacity: 0.7; cursor: wait; }

/* Toast & Shake */
.cw-toast { position: fixed; left: 50%; transform: translateX(-50%) translateY(20px); bottom: 30px; background: #1f2937; color: #fff; padding: 12px 20px; border-radius: 50px; font-size: 14px; font-weight: 500; z-index: 10000; box-shadow: 0 10px 30px rgba(0,0,0,0.2); opacity: 0; pointer-events: none; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.cw-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.cw-toast.error { background: #ef4444; }
.cw-toast.success { background: #10b981; }

@keyframes cw-shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }
.shake { animation: cw-shake 0.4s ease-in-out; }

@media (max-width: 768px) {
    .calendar-header { flex-direction: column; gap: 12px; }
    .summary-content { flex-direction: column; text-align: center; align-items: stretch; }
    .reserve-btn { width: 100%; }
}
</style>

<div class="coworking-calendar-container" id="cw-container" data-offre-id="<?php echo esc_attr($offre_id); ?>">
    <div class="calendar-card">
        <div class="calendar-header">
            <h2 class="calendar-title">Disponibilités</h2>
            <div class="calendar-nav">
                <button class="nav-btn" id="cw-prev" aria-label="Mois précédent">←</button>
                <div class="month-display" id="cw-month-label"></div>
                <button class="nav-btn" id="cw-next" aria-label="Mois suivant">→</button>
            </div>
        </div>

        <div class="calendar-legend">
            <div class="legend-item"><div class="legend-dot available"></div><span>Libre</span></div>
            <div class="legend-item"><div class="legend-dot selected"></div><span>Sélection</span></div>
            <div class="legend-item"><div class="legend-dot unavailable"></div><span>Complet</span></div>
        </div>

        <div class="calendar-grid" id="cw-grid">
        </div>

        <div class="selection-summary" id="cw-summary">
            <div class="summary-content">
                <div class="summary-col">
                    <span class="summary-label">Vos dates</span>
                    <span class="summary-value" id="cw-dates-display">-</span>
                </div>
                <div class="summary-col">
                    <span class="summary-label">Total estimé</span>
                    <div style="display:flex; align-items:baseline; gap:5px;">
                        <span class="price-amount" id="cw-price-display">-</span>
                        <span id="cw-duration-display" style="font-size:12px; opacity:0.8"></span>
                    </div>
                </div>
                <button class="reserve-btn" id="cw-book-btn">Réserver</button>
            </div>
        </div>
    </div>
</div>

<div id="cw-toast" class="cw-toast"></div>

<script>
(function() {
    // --- 1. CONFIGURATION & STATE ---
    const API_URL = window.location.origin + '/wp-json/coworking/v1';
    const CONFIG = {
        offreId: document.getElementById('cw-container')?.dataset.offreId,
        monthsFr: ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'],
        daysFr: ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'],
        todayIso: new Date().toISOString().split('T')[0]
    };

    const STATE = {
        currentMonth: new Date(),
        viewDateIso: null,
        formule: null,
        selection: { start: null, end: null },
        cache: {},
        isAnimating: false,
        prices: {
            journee: <?php echo $price_day ?: 0; ?>,
            semaine: <?php echo $price_week ?: 0; ?>,
            mois: <?php echo $price_month ?: 0; ?>
        }
    };

    // --- 2. DATE UTILS ---
    const DateUtils = {
        format: (d) => d.toISOString().split('T')[0],
        addDays: (isoStr, days) => {
            const parts = isoStr.split('-').map(Number);
            const d = new Date(parts[0], parts[1] - 1, parts[2]);
            d.setDate(d.getDate() + days);
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },
        isBefore: (a, b) => a < b,
        isAfter: (a, b) => a > b,
        isSame: (a, b) => a === b,
        toMonthKey: (dateObj) => {
            const y = dateObj.getFullYear();
            const m = String(dateObj.getMonth() + 1).padStart(2, '0');
            return `${y}-${m}`;
        },
        formatHuman: (isoStr) => {
            if(!isoStr) return '';
            const parts = isoStr.split('-');
            const d = new Date(parts[0], parts[1]-1, parts[2]);
            return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
        },
        formatHumanShort: (isoStr) => {
             const parts = isoStr.split('-');
             const d = new Date(parts[0], parts[1]-1, parts[2]);
             return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
        }
    };

    // --- 3. CORE LOGIC ---

    async function initCalendar(formuleType) {
        if (!CONFIG.offreId) return;
        
        STATE.formule = formuleType;
        STATE.selection = { start: null, end: null };
        
        const container = document.getElementById('cw-container');
        container.classList.add('active');
        container.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        updateSummaryUI(false);
        await renderMonth(STATE.currentMonth);
    }

    async function renderMonth(dateObj) {
        const monthKey = DateUtils.toMonthKey(dateObj);
        STATE.viewDateIso = monthKey;

        document.getElementById('cw-month-label').textContent = `${CONFIG.monthsFr[dateObj.getMonth()]} ${dateObj.getFullYear()}`;

        const grid = document.getElementById('cw-grid');
        
        if (!STATE.cache[monthKey]) {
            grid.innerHTML = '<div class="grid-loading">Chargement...</div>';
            try {
                const res = await fetch(`${API_URL}/availability/${CONFIG.offreId}?month=${monthKey}`);
                const data = await res.json();
                if (data.success) {
                    STATE.cache[monthKey] = data.availability;
                }
            } catch (e) {
                console.error("API Error", e);
                showToast("Erreur de connexion", "error");
                grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px">Erreur de chargement</div>';
                return;
            }
        }

        buildGrid(dateObj, STATE.cache[monthKey]);
    }

    function buildGrid(dateObj, availabilityData) {
        const grid = document.getElementById('cw-grid');
        grid.innerHTML = '';

        CONFIG.daysFr.forEach(d => {
            const el = document.createElement('div');
            el.className = 'day-header';
            el.textContent = d;
            grid.appendChild(el);
        });

        const year = dateObj.getFullYear();
        const month = dateObj.getMonth();
        
        const firstDay = new Date(year, month, 1).getDay();
        const blanks = (firstDay === 0 ? 6 : firstDay - 1);

        for (let i = 0; i < blanks; i++) {
            grid.appendChild(document.createElement('div'));
        }

        const daysInMonth = new Date(year, month + 1, 0).getDate();

        for (let d = 1; d <= daysInMonth; d++) {
            const dayStr = String(d).padStart(2, '0');
            const iso = `${year}-${String(month + 1).padStart(2, '0')}-${dayStr}`;
            const info = availabilityData[iso] || { status: 'unavailable' };
            
            const btn = document.createElement('div');
            btn.className = 'calendar-day';
            btn.textContent = d;
            btn.dataset.date = iso;

            // ⚠️ FIX: Traiter 'full' comme 'unavailable' visuellement
            if (info.status === 'unavailable' || info.status === 'full' || info.is_past) {
                btn.classList.add('unavailable');
                if (info.status === 'full') btn.classList.add('full'); // Optionnel pour ciblage CSS
                if (info.is_past) btn.classList.add('past');
            } else {
                btn.classList.add('available');
                // LOW = point jaune mais reste cliquable
                if (info.status === 'low') btn.classList.add('low');
            }

            applySelectionClasses(btn, iso);

            grid.appendChild(btn);
        }
    }

    function applySelectionClasses(el, iso) {
        if (!STATE.selection.start) return;

        const s = STATE.selection.start;
        const e = STATE.selection.end;

        if (iso === s || iso === e) {
            el.classList.add('selected');
        }
        
        if (s && e && iso > s && iso < e) {
            el.classList.add('in-range');
        }
    }

    // --- 4. INTERACTION & OPTIMISTIC UI ---

    document.getElementById('cw-grid').addEventListener('click', async (e) => {
        const cell = e.target.closest('.calendar-day');
        if (!cell || cell.classList.contains('unavailable')) return;

        const clickedDate = cell.dataset.date;
        handleSelection(clickedDate, cell);
    });

    async function handleSelection(startDateIso, cellElement) {
        let endDateIso = startDateIso;
        
        if (STATE.formule === 'semaine') {
            endDateIso = DateUtils.addDays(startDateIso, 6);
        } else if (STATE.formule === 'mois') {
            endDateIso = DateUtils.addDays(startDateIso, 29);
        }

        const previousSelection = { ...STATE.selection };

        STATE.selection = { start: startDateIso, end: endDateIso };
        refreshGridClasses();
        
        try {
            const isValid = await checkAvailabilityRange(startDateIso, endDateIso);
            
            if (!isValid) {
                throw new Error("Période indisponible");
            }
            
            updateSummaryUI(true);
            await fetchPrice(startDateIso, endDateIso);

        } catch (error) {
            STATE.selection = previousSelection;
            refreshGridClasses();
            
            cellElement.classList.add('shake');
            setTimeout(() => cellElement.classList.remove('shake'), 500);
            showToast("Cette période contient des dates indisponibles", "error");
        }
    }

    function refreshGridClasses() {
        const cells = document.querySelectorAll('.calendar-day');
        cells.forEach(cell => {
            cell.classList.remove('selected', 'in-range');
            const iso = cell.dataset.date;
            applySelectionClasses(cell, iso);
        });
    }

    async function checkAvailabilityRange(startIso, endIso) {
        const monthsToCheck = new Set();
        let curr = startIso;
        
        while (curr <= endIso) {
            monthsToCheck.add(curr.substring(0, 7));
            curr = DateUtils.addDays(curr, 15);
            if (curr > endIso && curr.substring(0, 7) !== endIso.substring(0, 7)) break; 
        }
        monthsToCheck.add(endIso.substring(0, 7));

        const promises = [];
        for (const mKey of monthsToCheck) {
            if (!STATE.cache[mKey]) {
                promises.push(
                    fetch(`${API_URL}/availability/${CONFIG.offreId}?month=${mKey}`)
                        .then(r => r.json())
                        .then(d => { if(d.success) STATE.cache[mKey] = d.availability; })
                );
            }
        }
        await Promise.all(promises);

        let d = startIso;
        while (d <= endIso) {
            const mKey = d.substring(0, 7);
            const data = STATE.cache[mKey];
            
            if (!data || !data[d]) return false;
            
            // ⚠️ FIX: Rejeter si status = 'full' OU 'unavailable'
            if (data[d].status === 'unavailable' || data[d].status === 'full' || data[d].slots <= 0) {
                return false;
            }
            d = DateUtils.addDays(d, 1);
        }

        return true;
    }

    async function fetchPrice(start, end) {
        const priceDisplay = document.getElementById('cw-price-display');
        const btn = document.getElementById('cw-book-btn');
        
        priceDisplay.textContent = '...';
        btn.disabled = true;
        btn.textContent = 'Calcul...';

        try {
            const res = await fetch(`${API_URL}/calculate-price`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    offre_id: CONFIG.offreId,
                    formule: STATE.formule,
                    start_date: start,
                    end_date: end
                })
            });
            const json = await res.json();
            
            if (json.success) {
                STATE.prices.current = json.price;
                priceDisplay.textContent = json.price + ' €';
                
                btn.disabled = false;
                btn.textContent = 'Réserver';
                
                btn.onclick = () => addToCart(json.price);
                
            } else {
                throw new Error(json.message);
            }
        } catch (e) {
            priceDisplay.textContent = '-';
            btn.textContent = 'Indisponible';
            showToast(e.message || "Erreur calcul prix", "error");
        }
    }

    async function addToCart(verifiedPrice) {
        const btn = document.getElementById('cw-book-btn');
        
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Validation...';
        
        try {
            const res = await fetch(`${API_URL}/cart-add`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    offre_id: CONFIG.offreId,
                    formule: STATE.formule,
                    start: STATE.selection.start,
                    end: STATE.selection.end
                })
            });

            const json = await res.json();

            if (json.success) {
                showToast("Réservation validée ! Redirection...", "success");
                btn.textContent = "Redirection...";
                
                window.location.href = json.cart_url; 
            } else {
                throw new Error(json.message);
            }

        } catch (e) {
            console.error("Booking Error", e);
            showToast(e.message || "Erreur technique lors de la réservation", "error");
            
            btn.disabled = false;
            btn.textContent = originalText;
            
            if (e.message.includes('disponible') || e.message.includes('date')) {
                renderMonth(STATE.currentMonth);
            }
        }
    }

    function updateSummaryUI(show) {
        const box = document.getElementById('cw-summary');
        if (!show) {
            box.classList.remove('active');
            return;
        }
        box.classList.add('active');

        const datesLabel = document.getElementById('cw-dates-display');
        const durationLabel = document.getElementById('cw-duration-display');
        
        const s = STATE.selection.start;
        const e = STATE.selection.end;

        if (s === e) {
            datesLabel.textContent = DateUtils.formatHuman(s);
            durationLabel.textContent = "(1 jour)";
        } else {
            datesLabel.textContent = `${DateUtils.formatHumanShort(s)} → ${DateUtils.formatHuman(e)}`;
            const days = (new Date(e) - new Date(s)) / (1000 * 60 * 60 * 24) + 1;
            durationLabel.textContent = `(${Math.round(days)} jours)`;
        }
    }

    // --- 5. NAVIGATION ---
    
    document.getElementById('cw-prev').addEventListener('click', () => {
        STATE.currentMonth.setMonth(STATE.currentMonth.getMonth() - 1);
        renderMonth(STATE.currentMonth);
    });

    document.getElementById('cw-next').addEventListener('click', () => {
        STATE.currentMonth.setMonth(STATE.currentMonth.getMonth() + 1);
        renderMonth(STATE.currentMonth);
    });

    function showToast(msg, type = 'info') {
        const t = document.getElementById('cw-toast');
        t.textContent = msg;
        t.className = `cw-toast show ${type}`;
        setTimeout(() => t.classList.remove('show'), 3000);
    }

    // --- 6. EXPORT / INITIALISATION EXTERNE ---
    window.initCoworkingCalendar = initCalendar;

    document.addEventListener('DOMContentLoaded', () => {
        const triggers = {
            'formule-journee': 'journee',
            'formule-semaine': 'semaine',
            'formule-mois': 'mois'
        };
        
        Object.keys(triggers).forEach(id => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.style.cursor = 'pointer';
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    document.querySelectorAll('.formule-active').forEach(b => b.classList.remove('formule-active'));
                    btn.classList.add('formule-active');
                    
                    initCalendar(triggers[id]);
                });
            }
        });
    });

})();
</script>
    <?php
    return ob_get_clean();
}

/**
 * Coworking Booking Engine
 */
/**
 * Coworking Booking Engine
 */
/**
 * Coworking Booking Engine - RGPD COMPLIANT
 * Version: 3.1 - Conforme RGPD + Smart Locks (Durée dynamique)
 */

/* ============================================================
   CONFIG - Durée des locks adaptée au stock
============================================================ */

/**
 * Fonction pour calculer la durée de lock optimale
 * - Salle de réunion (stock=1) : 20 minutes
 * - Bureaux privés (stock=7) : 5 minutes
 */
function cw_get_lock_ttl($offre_id) {
    $capacity = (int) get_field('capacite_max', $offre_id);
    
    if ($capacity <= 1) {
        // Salle de réunion ou espace unique : LOCK LONG (20 min)
        return 20 * MINUTE_IN_SECONDS;
    } else {
        // Bureaux multiples : LOCK COURT (5 min)
        return 5 * MINUTE_IN_SECONDS;
    }
}

/* 1) CPT registration */
add_action('init', function() {
    register_post_type('cw_reservation', [
        'labels' => ['name' => 'Réservations Coworking', 'singular_name' => 'Réservation'],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title', 'custom-fields'],
        'menu_icon' => 'dashicons-calendar-alt',
        'capabilities' => ['create_posts' => false],
        'map_meta_cap' => true,
    ]);
});

/* 2) Helper : resolve product id from ACF relationship */
function cw_resolve_product_id_from_offre($offre_id) {
    $field = get_field('produit_woocommerce', $offre_id);
    if (!$field) return 0;
    if (is_array($field)) {
        $first = reset($field);
        if (is_object($first) && isset($first->ID)) return intval($first->ID);
        if (is_numeric($first)) return intval($first);
    }
    if (is_object($field) && isset($field->ID)) return intval($field->ID);
    if (is_numeric($field)) return intval($field);
    return 0;
}

/* 3) Availability + locks helpers - RGPD SAFE */
function coworking_check_availability_with_locks($offre_id, $start_date, $end_date) {
    $capacity = (int) get_field('capacite_max', $offre_id);
    if ($capacity <= 0) $capacity = 1;

    // confirmed reservations
    $reservations_json = get_field('reservations_json', $offre_id) ?: '[]';
    $confirmed_res = json_decode($reservations_json, true);
    if (!is_array($confirmed_res)) $confirmed_res = [];

    // locks - PURGE DES EXPIRÉ D'ABORD
    $locks = get_transient('cw_locks_' . $offre_id);
    if (!is_array($locks)) $locks = [];
    $now = time();
    $locks = array_filter($locks, function($l) use ($now) { 
        return isset($l['expires_at']) && $l['expires_at'] > $now; 
    });
    
    // Mise à jour du transient avec la nouvelle durée dynamique
    set_transient('cw_locks_' . $offre_id, $locks, cw_get_lock_ttl($offre_id));

    // Dates bloquées manuelles
    $manual_raw = get_field('dates_indisponibles_manuel', $offre_id) ?: '';
    $manual_block = array_filter(array_map('trim', explode("\n", $manual_raw)));

    $current = strtotime($start_date);
    $end = strtotime($end_date);
    $is_valid = true;
    $first_fail = null;

    // 🔒 LOGS RGPD-SAFE (seulement en mode debug)
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("CHECK AVAILABILITY offre_id=$offre_id, range=$start_date→$end_date, capacity=$capacity");
    }

    while ($current <= $end) {
        $date_str = date('Y-m-d', $current);
        $count = 0;

        if (in_array($date_str, $manual_block)) {
            $is_valid = false;
            $first_fail = $date_str;
            break;
        }

        foreach ($confirmed_res as $r) {
            if (!isset($r['start']) || !isset($r['end'])) continue;
            if ($date_str >= $r['start'] && $date_str <= $r['end']) {
                $count += (int)($r['quantity'] ?? 1);
            }
        }

        foreach ($locks as $l) {
            if (!isset($l['start']) || !isset($l['end'])) continue;
            if ($date_str >= $l['start'] && $date_str <= $l['end']) {
                $count += (int)($l['quantity'] ?? 1);
            }
        }

        if ($count >= $capacity) {
            $is_valid = false;
            $first_fail = $date_str;
            break;
        }

        $current = strtotime('+1 day', $current);
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Result: " . ($is_valid ? "AVAILABLE" : "UNAVAILABLE (fail: $first_fail)"));
    }

    return ['available' => $is_valid, 'fail_date' => $first_fail];
}

/* Add / remove locks - MODIFIÉ POUR SMART LOCKS */
function coworking_add_lock($offre_id, $data) {
    $key = 'cw_locks_' . $offre_id;
    $locks = get_transient($key);
    if (!is_array($locks)) $locks = [];

    $now = time();
    $locks = array_filter($locks, function($l) use ($now) { 
        return isset($l['expires_at']) && $l['expires_at'] > $now; 
    });

    // ✅ NOUVEAU : Durée adaptée au stock
    $ttl = cw_get_lock_ttl($offre_id);

    $locks[] = [
        'start'      => $data['start'],
        'end'        => $data['end'],
        'quantity'   => 1,
        'expires_at' => time() + $ttl,
        'token'      => $data['token'],
        'lock_type'  => ($ttl >= 15 * MINUTE_IN_SECONDS) ? 'strict' : 'flexible'
    ];

    set_transient($key, $locks, $ttl);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Lock ajouté pour offre $offre_id : durée = " . ($ttl / 60) . " min");
    }
}

function coworking_remove_lock_by_token($offre_id, $token) {
    $key = 'cw_locks_' . $offre_id;
    $locks = get_transient($key);
    if (!is_array($locks)) $locks = [];
    
    $locks = array_filter($locks, function($l) use ($token) {
        return ($l['token'] ?? '') !== $token;
    });
    
    // Mise à jour du transient avec la durée dynamique
    set_transient($key, $locks, cw_get_lock_ttl($offre_id));
}

/* 4) Endpoint cart-add - RGPD SAFE */
add_action('rest_api_init', function() {
    register_rest_route('coworking/v1', '/cart-add', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $req) {

            $p = $req->get_json_params();
            $offre_id = (int) ($p['offre_id'] ?? 0);
            $formule  = sanitize_text_field($p['formule'] ?? '');
            $start    = sanitize_text_field($p['start'] ?? '');
            $end      = sanitize_text_field($p['end'] ?? '');

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("cart-add: offre=$offre_id, formule=$formule");
            }

            if (!$offre_id || !$formule || !$start || !$end) {
                return new WP_REST_Response(['success' => false, 'message' => 'Paramètres manquants'], 400);
            }

            if ($start <= date('Y-m-d')) {
                return new WP_REST_Response(['success' => false, 'message' => 'La date doit être au moins demain (J+1)'], 400);
            }

            // Prix final
            $price_day   = (float) get_field('prix_journee', $offre_id);
            $price_week  = (float) get_field('prix_semaine', $offre_id);
            $price_month = (float) get_field('prix_mois', $offre_id);

            $final_price = 0;
            if ($formule === 'journee') $final_price = $price_day;
            elseif ($formule === 'semaine') $final_price = $price_week ?: ($price_day * 5);
            elseif ($formule === 'mois') $final_price = $price_month ?: ($price_week * 4);
            else $final_price = $price_day;

            if ($final_price <= 0) {
                return new WP_REST_Response(['success' => false, 'message' => 'Erreur configuration prix'], 500);
            }

            // Availability check
            $check = coworking_check_availability_with_locks($offre_id, $start, $end);
            if (!$check['available']) {
                return new WP_REST_Response([
                    'success' => false, 
                    'message' => 'Date indisponible : ' . date('d/m/Y', strtotime($check['fail_date']))
                ], 409);
            }

            // Resolve product
            $product_id = cw_resolve_product_id_from_offre($offre_id);
            if (!$product_id) {
                $title = strtolower(get_the_title($offre_id));
                if (strpos($title, 'bureau') !== false) $product_id = 1913;
                if (strpos($title, 'salle') !== false) $product_id = 1917;
            }
            
            if (!$product_id) {
                return new WP_REST_Response(['success' => false, 'message' => 'Produit WooCommerce non configuré'], 500);
            }

            if (!function_exists('WC')) {
                return new WP_REST_Response(['success'=>false,'message'=>'WooCommerce non actif'], 500);
            }

            // Create lock
            $cart_token = wp_generate_password(12, false);
            coworking_add_lock($offre_id, ['start' => $start, 'end' => $end, 'token' => $cart_token]);

            // Cart
            if (!WC()->session) WC()->initialize_session();
            wc_load_cart();

            $cart_item_data = [
                'coworking_data' => [
                    'offre_id'   => $offre_id,
                    'offre_name' => get_the_title($offre_id),
                    'formule'    => $formule,
                    'start'      => $start,
                    'end'        => $end,
                    'real_price' => (float)$final_price,
                    'lock_token' => $cart_token
                ]
            ];

            $added = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);
            if (!$added) {
                coworking_remove_lock_by_token($offre_id, $cart_token);
                return new WP_REST_Response(['success' => false, 'message' => 'Erreur ajout panier'], 500);
            }

            // Draft reservation
            $resa_id = wp_insert_post([
                'post_type'  => 'cw_reservation',
                'post_title' => sprintf('Temp - Offre %d - %s', $offre_id, $start),
                'post_status'=> 'draft'
            ]);
            
            if ($resa_id) {
                update_post_meta($resa_id, '_cw_offre_id', $offre_id);
                update_post_meta($resa_id, '_cw_offre_name', get_the_title($offre_id));
                update_post_meta($resa_id, '_cw_formule', $formule);
                update_post_meta($resa_id, '_cw_start', $start);
                update_post_meta($resa_id, '_cw_end', $end);
                update_post_meta($resa_id, '_cw_price', $final_price);
                update_post_meta($resa_id, '_cw_lock_token', $cart_token);
            }

            return new WP_REST_Response(['success' => true, 'cart_url' => wc_get_cart_url()], 200);
        }
    ]);
});

/* 5-7) Cart & Order handling */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    foreach ($cart->get_cart() as $cart_item) {
        if (!empty($cart_item['coworking_data']['real_price'])) {
            $cart_item['data']->set_price((float) $cart_item['coworking_data']['real_price']);
        }
    }
}, 10);

add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    if (!empty($cart_item['coworking_data'])) {
        $d = $cart_item['coworking_data'];
        $item_data[] = ['key' => 'Offre', 'value' => $d['offre_name'] ?? ''];
        $item_data[] = ['key' => 'Formule', 'value' => ucfirst($d['formule'] ?? '')];
        $item_data[] = ['key' => 'Date début', 'value' => date_i18n('d/m/Y', strtotime($d['start'] ?? ''))];
        $item_data[] = ['key' => 'Date fin', 'value' => date_i18n('d/m/Y', strtotime($d['end'] ?? ''))];
    }
    return $item_data;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order) {
    if (!empty($values['coworking_data'])) {
        $d = $values['coworking_data'];
        $item->add_meta_data('_cw_offre_id', $d['offre_id'] ?? '');
        $item->add_meta_data('_cw_offre_name', $d['offre_name'] ?? '');
        $item->add_meta_data('_cw_start', $d['start'] ?? '');
        $item->add_meta_data('_cw_end', $d['end'] ?? '');
        $item->add_meta_data('_cw_formule', $d['formule'] ?? '');
        $item->add_meta_data('_cw_price', $d['real_price'] ?? '');
        $item->add_meta_data('_cw_lock_token', $d['lock_token'] ?? '');
        
        $item->add_meta_data('Offre', $d['offre_name'] ?? '', true);
        $item->add_meta_data('Formule', ucfirst($d['formule'] ?? ''), true);
        $item->add_meta_data('Du', date_i18n('d/m/Y', strtotime($d['start'] ?? '')), true);
        $item->add_meta_data('Au', date_i18n('d/m/Y', strtotime($d['end'] ?? '')), true);
    }
}, 10, 4);

/* 8) FINALIZATION - RGPD COMPLIANT */
add_action('woocommerce_order_status_completed', 'coworking_finalize_reservation');
add_action('woocommerce_order_status_processing', 'coworking_finalize_reservation');

function coworking_finalize_reservation($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_meta('_cw_processed')) return;

    // ✅ VÉRIFICATION RGPD : Le consentement a-t-il été donné ?
    $rgpd_consent = $order->get_meta('_cw_rgpd_consent');
    if ($rgpd_consent !== 'yes') {
        // Si pas de consentement, on ne stocke PAS les données perso
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("RGPD: No consent for order $order_id - skipping personal data storage");
        }
        return;
    }

    foreach ($order->get_items() as $item) {
        $offre_id = $item->get_meta('_cw_offre_id');
        if (!$offre_id) continue;

        $start = $item->get_meta('_cw_start');
        $end   = $item->get_meta('_cw_end');
        $formule = $item->get_meta('_cw_formule');
        $price = $item->get_meta('_cw_price');
        $lock_token = $item->get_meta('_cw_lock_token');
        $offre_name = $item->get_meta('_cw_offre_name') ?: get_the_title($offre_id);

        // Update JSON
        $json = get_field('reservations_json', $offre_id) ?: '[]';
        $reservations = json_decode($json, true);
        if (!is_array($reservations)) $reservations = [];

        $reservations[] = [
            'start' => $start,
            'end' => $end,
            'formule' => $formule,
            'quantity' => 1,
            'order' => (int)$order_id
        ];
        
        update_field('reservations_json', json_encode(array_values($reservations), JSON_PRETTY_PRINT), $offre_id);

        // CPT reservation
        $existing = get_posts([
            'post_type' => 'cw_reservation',
            'meta_query' => [['key' => '_cw_lock_token', 'value' => $lock_token]],
            'post_status' => 'draft',
            'posts_per_page' => 1
        ]);

        if (!empty($existing)) {
            $post_id = $existing[0]->ID;
            wp_update_post([
                'ID' => $post_id,
                'post_title' => sprintf('Résa #%s - %s', $order_id, $offre_name),
                'post_status' => 'publish'
            ]);
        } else {
            $post_id = wp_insert_post([
                'post_type' => 'cw_reservation',
                'post_title' => sprintf('Résa #%s - %s', $order_id, $offre_name),
                'post_status' => 'publish'
            ]);
        }

        if ($post_id) {
            update_post_meta($post_id, '_cw_offre_id', $offre_id);
            update_post_meta($post_id, '_cw_offre_name', $offre_name);
            update_post_meta($post_id, '_cw_formule', $formule);
            update_post_meta($post_id, '_cw_start', $start);
            update_post_meta($post_id, '_cw_end', $end);
            update_post_meta($post_id, '_cw_price', $price);
            
            // ✅ RGPD: Stockage avec base légale (consentement donné)
            update_post_meta($post_id, '_cw_customer_name', $order->get_formatted_billing_full_name());
            update_post_meta($post_id, '_cw_customer_email', $order->get_billing_email());
            update_post_meta($post_id, '_cw_order_id', $order_id);
            update_post_meta($post_id, '_cw_rgpd_consent_date', $order->get_meta('_cw_rgpd_consent_date'));
            update_post_meta($post_id, '_cw_lock_token', '');
        }

        coworking_remove_lock_by_token($offre_id, $lock_token);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Reservation finalized: Order $order_id");
        }
    }

    $order->update_meta_data('_cw_processed', 'yes');
    $order->save();
}

/* 9) CANCELLATION */
add_action('woocommerce_order_status_cancelled', 'coworking_cancel_reservation');
add_action('woocommerce_order_status_refunded', 'coworking_cancel_reservation');

function coworking_cancel_reservation($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !$order->get_meta('_cw_processed')) return;

    foreach ($order->get_items() as $item) {
        $offre_id = $item->get_meta('_cw_offre_id');
        if (!$offre_id) continue;

        // Remove from JSON
        $json = get_field('reservations_json', $offre_id) ?: '[]';
        $res = json_decode($json, true);
        if (is_array($res)) {
            $new_res = array_filter($res, function($r) use ($order_id) {
                return !isset($r['order']) || $r['order'] != $order_id;
            });
            update_field('reservations_json', json_encode(array_values($new_res), JSON_PRETTY_PRINT), $offre_id);
        }

        // Trash CPT
        $q = new WP_Query([
            'post_type' => 'cw_reservation',
            'meta_key' => '_cw_order_id',
            'meta_value' => $order_id,
            'posts_per_page' => -1
        ]);
        
        if ($q->have_posts()) {
            while ($q->have_posts()) { 
                $q->the_post(); 
                wp_update_post(['ID' => get_the_ID(), 'post_status' => 'trash']); 
            }
            wp_reset_postdata();
        }
    }

    $order->delete_meta_data('_cw_processed');
    $order->save();
}

/* 10-11) Stock management */
add_filter('woocommerce_product_get_manage_stock', 'cw_disable_stock_management', 10, 2);
add_filter('woocommerce_product_variation_get_manage_stock', 'cw_disable_stock_management', 10, 2);

function cw_disable_stock_management($value, $product) {
    $coworking_ids = [1913, 1917];
    if (in_array($product->get_id(), $coworking_ids)) return false;
    return $value;
}

add_filter('woocommerce_product_is_in_stock', 'cw_force_in_stock', 10, 2);
function cw_force_in_stock($status, $product) {
    $coworking_ids = [1913, 1917];
    if (in_array($product->get_id(), $coworking_ids)) return true;
    return $status;
}

/* 12) Final security check */
add_action('woocommerce_check_cart_items', 'cw_final_security_check');

function cw_final_security_check() {
    if (is_admin() && !defined('DOING_AJAX')) return;

    $cart = WC()->cart->get_cart();

    foreach ($cart as $item_key => $item) {
        if (empty($item['coworking_data'])) continue;

        $d = $item['coworking_data'];
        $offre_id = (int)$d['offre_id'];
        $start = $d['start'];
        $end = $d['end'];
        $my_token = $d['lock_token'] ?? '';

        $locks = get_transient('cw_locks_' . $offre_id);
        if (!is_array($locks)) $locks = [];
        
        $now = time();
        $active_locks = [];
        $i_still_have_my_lock = false;

        foreach ($locks as $l) {
            if (isset($l['expires_at']) && $l['expires_at'] > $now) {
                $active_locks[] = $l;
                if (isset($l['token']) && $l['token'] === $my_token) {
                    $i_still_have_my_lock = true;
                }
            }
        }

        if ($i_still_have_my_lock) continue;
        
        // Mise à jour du transient avec la durée dynamique
        set_transient('cw_locks_' . $offre_id, $active_locks, cw_get_lock_ttl($offre_id));
        
        $check = coworking_check_availability_with_locks($offre_id, $start, $end);

        if (!$check['available']) {
            wc_add_notice(sprintf(
                "<strong>Attention :</strong> Le créneau du %s n'est plus disponible.",
                date_i18n('d/m/Y', strtotime($start))
            ), 'error');
            
            WC()->cart->remove_cart_item($item_key);
        } else {
            coworking_add_lock($offre_id, ['start' => $start, 'end' => $end, 'token' => $my_token]);
        }
    }
}

/**
 * Coworking Admin Metabox
 */
/**
 * coworking-admin-metabox.code-snippets.php
 * Métabox admin (lecture seule) pour cw_reservation
 * + injection d'une section "Réservation" dans les emails WooCommerce
 */

/* ------------------------------------------------------------
   Helpers (formatage)
------------------------------------------------------------*/

if (!function_exists('cw_format_date_fr')) {
    function cw_format_date_fr($iso) {
        if (!$iso) return '';
        $ts = strtotime($iso);
        if ($ts === false) return esc_html($iso);
        return date_i18n('d/m/Y', $ts);
    }
}

if (!function_exists('cw_format_price')) {
    function cw_format_price($amount) {
        if ($amount === '' || $amount === null) return '';
        return wc_price((float)$amount);
    }
}

/* ------------------------------------------------------------
   Metabox Admin : affichage lecture seule
------------------------------------------------------------*/

add_action('add_meta_boxes', function() {
    add_meta_box(
        'cw_reservation_details',
        '📅 Détails de la réservation',
        'cw_render_reservation_metabox',
        'cw_reservation',
        'normal',
        'high'
    );
});

/**
 * Render metabox content (lecture seule)
 */
function cw_render_reservation_metabox($post) {
    // Cap check
    if (!current_user_can('edit_posts')) {
        echo '<p>Accès restreint.</p>';
        return;
    }

    // Récupérer les metas propres (8 champs attendus)
    $offre_id   = get_post_meta($post->ID, '_cw_offre_id', true);
    $offre_name = get_post_meta($post->ID, '_cw_offre_name', true);
    $formule    = get_post_meta($post->ID, '_cw_formule', true);
    $start      = get_post_meta($post->ID, '_cw_start', true);
    $end        = get_post_meta($post->ID, '_cw_end', true);
    $price      = get_post_meta($post->ID, '_cw_price', true);
    $cust_name  = get_post_meta($post->ID, '_cw_customer_name', true);
    $order_id   = get_post_meta($post->ID, '_cw_order_id', true);

    // Fallbacks lisibles
    if (!$offre_name && $offre_id) {
        $offre_name = get_the_title($offre_id);
    }

    // Format
    $formule_label = $formule ? ucfirst($formule) : '-';
    $start_fr = cw_format_date_fr($start);
    $end_fr   = cw_format_date_fr($end);
    $price_fmt = cw_format_price($price);

    // Lien admin vers la commande WooCommerce si existe
    $order_link = '';
    if ($order_id) {
        $order_post = get_post($order_id);
        if ($order_post) {
            $order_edit_url = admin_url('post.php?post=' . intval($order_id) . '&action=edit');
            $order_link = sprintf('<a href="%s">#%d</a>', esc_url($order_edit_url), intval($order_id));
        } else {
            $order_link = '#' . intval($order_id);
        }
    }

    // Lien vers l'offre (front / back)
    $offre_edit_link = '';
    if ($offre_id) {
        $edit_url = admin_url('post.php?post=' . intval($offre_id) . '&action=edit');
        $permalink = get_permalink($offre_id);
        $offre_edit_link = sprintf(
            '<a href="%s" target="_blank">%s</a> <small>(<a href="%s" target="_blank">Voir la fiche</a>)</small>',
            esc_url($edit_url),
            esc_html($offre_name ?: 'Offre #' . intval($offre_id)),
            esc_url($permalink)
        );
    }

    // Output HTML (lecture seule)
    ?>
    <div style="font-family:system-ui, -apple-system, Roboto, 'Segoe UI', Arial; line-height:1.45;">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="width:160px; padding:6px 8px; vertical-align:top; font-weight:600;">Offre</td>
                <td style="padding:6px 8px;"><?php echo $offre_edit_link ? $offre_edit_link : esc_html($offre_name ?: '—'); ?></td>
            </tr>

            <tr>
                <td style="padding:6px 8px; font-weight:600;">Formule</td>
                <td style="padding:6px 8px;"><?php echo esc_html($formule_label); ?></td>
            </tr>

            <tr>
                <td style="padding:6px 8px; font-weight:600;">Du</td>
                <td style="padding:6px 8px;"><?php echo esc_html($start_fr ?: '—'); ?></td>
            </tr>

            <tr>
                <td style="padding:6px 8px; font-weight:600;">Au</td>
                <td style="padding:6px 8px;"><?php echo esc_html($end_fr ?: '—'); ?></td>
            </tr>

            <tr>
                <td style="padding:6px 8px; font-weight:600;">Prix</td>
                <td style="padding:6px 8px;"><?php echo $price_fmt ?: '—'; ?></td>
            </tr>

            <tr>
                <td style="padding:6px 8px; font-weight:600;">Client</td>
                <td style="padding:6px 8px;"><?php echo esc_html($cust_name ?: '—'); ?></td>
            </tr>

            <tr>
                <td style="padding:6px 8px; font-weight:600;">Commande</td>
                <td style="padding:6px 8px;"><?php echo $order_link ?: '—'; ?></td>
            </tr>
        </table>

        <?php
        // Optionnel : afficher meta brutes pour debug (commenté)
        // echo '<pre style="margin-top:8px;">' . esc_html(print_r(get_post_meta($post->ID), true)) . '</pre>';
        ?>
    </div>
    <?php
}

/* ------------------------------------------------------------
   Emails WooCommerce : injecter une section "Détails réservation"
   (s'affiche dans l'email de commande côté client)
------------------------------------------------------------*/

/**
 * Affiche les réservations associées à une commande dans les emails.
 * Hook sur 'woocommerce_email_after_order_table' pour apparaître sous le tableau commande.
 */
add_action('woocommerce_email_after_order_table', 'cw_email_reservation_section', 10, 4);

function cw_email_reservation_section($order, $sent_to_admin, $plain_text, $email) {
    // On ne veut pas injecter cette section dans tous les emails (ex: admin new order)
    // Nous ciblons les emails destinés au client (customer processing/completed)
    if ($sent_to_admin) return;

    // Parcourir les items de la commande, chercher les metas _cw_offre_id
    $items = $order->get_items();
    $reservations = [];

    foreach ($items as $item) {
        $offre_id = $item->get_meta('_cw_offre_id', true);
        if (!$offre_id) continue;

        $reservations[] = [
            'offre_name' => $item->get_meta('_cw_offre_name', true) ?: get_the_title($offre_id),
            'formule'    => $item->get_meta('_cw_formule', true),
            'start'      => $item->get_meta('_cw_start', true),
            'end'        => $item->get_meta('_cw_end', true),
            'price'      => $item->get_meta('_cw_price', true),
        ];
    }

    if (empty($reservations)) return;

    // Rendu HTML ou texte selon le mail
    if ($plain_text) {
        echo "\n---- Détails de votre réservation ----\n";
        foreach ($reservations as $r) {
            echo 'Offre : ' . strip_tags($r['offre_name']) . "\n";
            echo 'Formule : ' . ucfirst($r['formule']) . "\n";
            echo 'Du : ' . cw_format_date_fr($r['start']) . "\n";
            echo 'Au : ' . cw_format_date_fr($r['end']) . "\n";
            echo 'Prix : ' . strip_tags(cw_format_price($r['price'])) . "\n";
            echo "-------------------------------------\n";
        }
        echo "\n";
    } else {
        // HTML
        echo '<h2 style="font-size:18px; margin-top:20px; margin-bottom:10px;">🔔 Détails de votre réservation</h2>';
        echo '<table cellspacing="0" cellpadding="6" style="width:100%; border-collapse:collapse;">';
        foreach ($reservations as $r) {
            echo '<tr>';
            echo '<td style="width:160px; font-weight:600; vertical-align:top;">Offre</td>';
            echo '<td>' . esc_html($r['offre_name']) . '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td style="font-weight:600;">Formule</td>';
            echo '<td>' . esc_html(ucfirst($r['formule'])) . '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td style="font-weight:600;">Du</td>';
            echo '<td>' . esc_html(cw_format_date_fr($r['start'])) . '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td style="font-weight:600;">Au</td>';
            echo '<td>' . esc_html(cw_format_date_fr($r['end'])) . '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td style="font-weight:600;">Prix</td>';
            echo '<td>' . cw_format_price($r['price']) . '</td>';
            echo '</tr>';

            // spacer
            echo '<tr><td colspan="2" style="padding-top:8px;"></td></tr>';
        }
        echo '</table>';
    }
}

/* ------------------------------------------------------------
   End file
------------------------------------------------------------*/

/**
 * Coworking CRON
 */
/**
 * coworking-cron.code-snippets.php
 * CRON léger pour maintenance du système de réservation
 *
 * - Nettoie les transients de locks expirés
 * - Supprime les brouillons cw_reservation vieux de 24h
 * - Répare (optionnel) reservations_json si incohérences
 */

/* ------------------------------------------------------------
   1) Enregistrer un event CRON quotidien
------------------------------------------------------------*/

add_action('init', function() {
    if (!wp_next_scheduled('coworking_daily_maintenance')) {
        wp_schedule_event(strtotime('03:00:00'), 'daily', 'coworking_daily_maintenance');
    }
});


/* ------------------------------------------------------------
   2) Routine principale
------------------------------------------------------------*/
/**
 * RGPD - Anonymiser les réservations > 3 ans
 */
function coworking_anonymize_old_reservations() {
    $threshold = strtotime('-3 years');
    
    $old_reservations = get_posts([
        'post_type' => 'cw_reservation',
        'post_status' => 'publish',
        'date_query' => [
            ['before' => gmdate('Y-m-d H:i:s', $threshold)]
        ],
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    if (empty($old_reservations)) return;
    
    $anonymized_count = 0;
    
    foreach ($old_reservations as $resa_id) {
        // Vérifier si déjà anonymisé
        $current_email = get_post_meta($resa_id, '_cw_customer_email', true);
        if ($current_email === 'anonyme@rgpd.local') continue;
        
        // Anonymiser les données personnelles
        update_post_meta($resa_id, '_cw_customer_name', 'Client anonymisé (RGPD)');
        update_post_meta($resa_id, '_cw_customer_email', 'anonyme@rgpd.local');
        
        // Ajouter une note d'anonymisation
        update_post_meta($resa_id, '_cw_anonymized_date', current_time('mysql'));
        
        $anonymized_count++;
    }
    
    if ($anonymized_count > 0) {
        error_log("RGPD: $anonymized_count réservations anonymisées automatiquement");
    }
}

add_action('coworking_daily_maintenance', 'coworking_run_daily_maintenance');

function coworking_run_daily_maintenance() {
    coworking_clean_expired_locks();
    coworking_clean_old_drafts();
    coworking_repair_reservations_json();
	coworking_anonymize_old_reservations();
}

/* ------------------------------------------------------------
   3) Nettoyage des locks expirés (transients)
------------------------------------------------------------*/

function coworking_clean_expired_locks() {
    global $wpdb;

    // Récupère tous les transients liés aux locks
    $pattern = '_transient_timeout_cw_locks_%';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value 
             FROM $wpdb->options 
             WHERE option_name LIKE %s",
            $pattern
        )
    );

    $now = time();
    $cleaned = 0;

    foreach ($rows as $row) {
        $expires_at = intval($row->option_value);
        if ($expires_at < $now) {
            // supprime le timeout + la valeur
            $key = str_replace('_transient_timeout_', '', $row->option_name);
            delete_transient($key);
            $cleaned++;
        }
    }

    if ($cleaned > 0) {
        error_log("Coworking CRON: $cleaned locks expirés supprimés");
    }
}

/* ------------------------------------------------------------
   4) Supprimer les brouillons cw_reservation > 24h
------------------------------------------------------------*/

function coworking_clean_old_drafts() {

    $threshold = strtotime('-24 hours');

    $old = get_posts([
        'post_type'      => 'cw_reservation',
        'post_status'    => 'draft',
        'date_query'     => [['before' => gmdate('Y-m-d H:i:s', $threshold)]],
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);

    if (!empty($old)) {
        foreach ($old as $id) {
            wp_delete_post($id, true);
        }
        error_log("Coworking CRON: ".count($old)." brouillons supprimés");
    }
}

/* ------------------------------------------------------------
   5) Réparer automatiquement reservations_json
      (option — léger mais utile)
------------------------------------------------------------*/

function coworking_repair_reservations_json() {
    $offres = get_posts([
        'post_type'      => 'offres-coworking', // ⚠️ ADAPTE SI TON SLUG EST DIFFÉRENT
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);

    if (empty($offres)) return;

    foreach ($offres as $id) {
        $json = get_field('reservations_json', $id);
        if (!$json) continue;

        $data = json_decode($json, true);
        if (!is_array($data)) {
            // JSON cassé ou vide → on réinitialise proprement
            update_field('reservations_json', json_encode([], JSON_PRETTY_PRINT), $id);
            error_log("Coworking CRON: JSON réparé pour offre $id");
            continue;
        }

        // Nettoyage des entrées invalides (si jamais)
        $fixed = array_values(array_filter($data, function($r) {
            return isset($r['start'], $r['end'], $r['order']);
        }));

        if (count($fixed) !== count($data)) {
            update_field('reservations_json', json_encode($fixed, JSON_PRETTY_PRINT), $id);
            error_log("Coworking CRON: JSON nettoyé pour offre $id");
        }
    }
}

/* ------------------------------------------------------------
   FIN DU FICHIER
------------------------------------------------------------*/

/**
 * Coworking JSON Tools
 */
/**
 * Coworking JSON Tools (Version Code Snippets Compatible)
 * Aucune dépendance WooCommerce_loaded
 * Se charge partout et garantit l’enregistrement des routes REST
 */

/* -------------------------------------------------------------
   0) DEBUG pour vérifier que le snippet s'exécute
------------------------------------------------------------- */
error_log("[COWORKING-5/7] Snippet chargé OK");

/* -------------------------------------------------------------
   1) Clean JSON
------------------------------------------------------------- */
function cw_clean_reservations_json_array($arr) {
    if (!is_array($arr)) return [];

    $clean = [];

    foreach ($arr as $item) {

        if (!is_array($item)) continue;

        $start = $item['start'] ?? '';
        $end   = $item['end'] ?? '';
        $form  = $item['formule'] ?? '';
        $qty   = intval($item['quantity'] ?? 1);
        $order = intval($item['order'] ?? 0);

        if (!$start || !$end) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) continue;

        $clean[] = [
            'start'   => $start,
            'end'     => $end,
            'formule' => $form,
            'quantity'=> max(1, $qty),
            'order'   => $order
        ];
    }

    return array_values($clean);
}

/* -------------------------------------------------------------
   2) Rebuild JSON depuis les commandes WooCommerce
------------------------------------------------------------- */
function cw_rebuild_reservations_json($offre_id) {

    if (!function_exists('wc_get_orders')) {
        return ['error' => 'WooCommerce non chargé'];
    }

    $orders = wc_get_orders([
        'status' => ['processing', 'completed'],
        'limit'  => -1
    ]);

    $rows = [];

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {

            $item_offre = intval($item->get_meta('_cw_offre_id'));
            if ($item_offre !== intval($offre_id)) continue;

            $start   = $item->get_meta('_cw_start');
            $end     = $item->get_meta('_cw_end');
            $formule = $item->get_meta('_cw_formule');
            $order_id = $order->get_id();

            if (!$start || !$end) continue;

            $rows[] = [
                'start'   => $start,
                'end'     => $end,
                'formule' => $formule,
                'quantity'=> 1,
                'order'   => intval($order_id)
            ];
        }
    }

    $rows = cw_clean_reservations_json_array($rows);

    update_field('reservations_json', json_encode($rows, JSON_PRETTY_PRINT), $offre_id);

    return $rows;
}

/* ==============================================================
   3) Endpoint REST sécurisé PAR CLÉ SECRÈTE
   - Pas besoin d'être connecté
   - Wordfence ne bloque pas
   - Pas d'accès si la clé n'est pas fournie
============================================================== */

add_action('rest_api_init', function() {


});


/* -------------------------------------------------------------
   4) Test manuel via URL : ?test_rebuild_json=ID
------------------------------------------------------------- */
add_action('init', function() {
    if (isset($_GET['test_rebuild_json']) && current_user_can('manage_options')) {
        $offre_id = intval($_GET['test_rebuild_json']);
        $result = cw_rebuild_reservations_json($offre_id);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }
});

/**
 * Coworking WC Order Complete
 */
/**
 * 6/7 — Création d'une réservation CPT quand la commande WooCommerce devient "completed"
 * Version : 1.0
 */

add_action('woocommerce_order_status_completed', 'cw_create_reservation_on_completed_order');

function cw_create_reservation_on_completed_order($order_id) {

    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    foreach ($order->get_items() as $item_id => $item) {

        // Vérifier si c’est bien une réservation coworking
        $offre_id = intval($item->get_meta('_cw_offre_id'));
        if (!$offre_id) continue;

        $start   = $item->get_meta('_cw_start');
        $end     = $item->get_meta('_cw_end');
        $formule = $item->get_meta('_cw_formule');
        $price   = floatval($item->get_meta('_cw_price'));

        // Vérification minimale
        if (!$start || !$end) continue;

        // Créer un CPT "cw_reservation"
        $resa_post_id = wp_insert_post([
            'post_type'   => 'cw_reservation',
            'post_title'  => "Réservation #$order_id • Offre $offre_id",
            'post_status' => 'publish',
        ]);

        if (!$resa_post_id) continue;

        // Stockage des données utiles
        update_post_meta($resa_post_id, '_cw_offre_id', $offre_id);
        update_post_meta($resa_post_id, '_cw_start_date', $start);
        update_post_meta($resa_post_id, '_cw_end_date', $end);
        update_post_meta($resa_post_id, '_cw_formula', $formule);
        update_post_meta($resa_post_id, '_cw_price', $price);

        update_post_meta($resa_post_id, '_cw_order_id', $order_id);
        update_post_meta($resa_post_id, '_cw_customer_id', $order->get_customer_id());
        update_post_meta($resa_post_id, '_cw_customer_email', $order->get_billing_email());
        update_post_meta($resa_post_id, '_cw_customer_name', $order->get_formatted_billing_full_name());

        update_post_meta($resa_post_id, '_cw_created_at', time());

        // Mise à jour automatique du JSON dans l'offre
        cw_update_offer_json_after_reservation($offre_id, $start, $end, $formule, $order_id);
    }
}


/**
 * Ajoute la réservation au JSON de l’offre
 */
function cw_update_offer_json_after_reservation($offre_id, $start, $end, $formule, $order_id) {

    $json = get_field('reservations_json', $offre_id);
    $arr  = json_decode($json, true);
    if (!is_array($arr)) $arr = [];

    $arr[] = [
        'start'    => $start,
        'end'      => $end,
        'formule'  => $formule,
        'quantity' => 1,
        'order'    => intval($order_id)
    ];

    // Nettoyage du JSON
    if (function_exists('cw_clean_reservations_json_array')) {
        $arr = cw_clean_reservations_json_array($arr);
    }

    update_field('reservations_json', json_encode($arr, JSON_PRETTY_PRINT), $offre_id);
}

/**
 * WooCommerce - Tunnel de vente (Invisible & Simplifié)
 */
/**
 * 1. SÉCURITÉ : Rendre la boutique "Invisible"
 * Si quelqu'un essaie d'aller sur /boutique, /produit/xyz, ou /categorie-produit/
 * il sera redirigé vers l'accueil.
 */
add_action('template_redirect', function() {
    // Liste des pages à bloquer
    $is_shop_page = is_shop();
    $is_product   = is_product();           // Page produit individuelle
    $is_category  = is_product_category();  // Archive catégorie
    $is_tag       = is_product_tag();

    // On laisse passer UNIQUEMENT le Panier (cart), la Caisse (checkout) et le Compte (my-account)
    // Note: On laisse "is_cart()" accessible pour que la redirection vers le checkout fonctionne
    if ($is_shop_page || $is_product || $is_category || $is_tag) {
        wp_safe_redirect(home_url()); // Redirection vers l'accueil
        exit;
    }
});

/**
 * 2. FLUX : Sauter l'étape "Panier" (Direct Checkout)
 * Quand votre script JS ajoute au panier, WooCommerce redirige directement vers la caisse.
 */
add_filter('woocommerce_add_to_cart_redirect', function($url) {
    return wc_get_checkout_url();
});

// Sécurité supplémentaire : si on accède manuellement à /panier/, on va au checkout
add_action('template_redirect', function() {
    if (is_cart() && !WC()->cart->is_empty()) {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
});

/**
 * 3. UI : Simplifier les champs de la page de Paiement (Checkout)
 * On ne garde que l'essentiel pour du service.
 */
add_filter('woocommerce_checkout_fields', function($fields) {
    // Supprimer toute la section expédition
    unset($fields['shipping']);

    // Nettoyer les champs de facturation
    // On garde : first_name, last_name, email, phone.
    unset($fields['billing']['billing_company']);   // Société
    unset($fields['billing']['billing_country']);   // Pays
    unset($fields['billing']['billing_address_1']); // Adresse 1
    unset($fields['billing']['billing_address_2']); // Adresse 2
    unset($fields['billing']['billing_city']);      // Ville
    unset($fields['billing']['billing_state']);     // État/Région
    unset($fields['billing']['billing_postcode']);  // Code postal

    // Supprimer les commentaires de commande ("Notes")
    unset($fields['order']['order_comments']);

    return $fields;
});

// Rendre les champs restants (Nom, Prénom, Email) obligatoires ou non
// WooCommerce le fait déjà par défaut, mais on s'assure que le téléphone est requis si besoin
add_filter( 'woocommerce_billing_fields', function($fields) {
    $fields['billing_phone']['required'] = true; // Mettre à false si le téléphone est optionnel
    return $fields;
});

/**
 * 4. VISUEL : Cacher l'affichage du prix "0€" ou "Gratuit"
 * Utile si le thème tente d'afficher le prix du produit avant le calcul final.
 */
add_filter('woocommerce_get_price_html', function($price, $product) {
    // Vos IDs produits coworking (Bureau et Salle)
    // Modifiez ces IDs si nécessaire (vous m'avez donné 1913 et 1917 dans le fichier précédent)
    $coworking_ids = [1913, 1917];

    if (in_array($product->get_id(), $coworking_ids)) {
        return ''; // Retourne vide
    }
    return $price;
}, 10, 2);

/**
 * 5. RETIRER les liens "Boutique" du fil d'ariane (Breadcrumb)
 */
add_filter('woocommerce_breadcrumb_defaults', function($defaults) {
    unset($defaults['home']);
    return $defaults;
});

// Supprime les notices " a été ajouté au panier" puisqu'on va direct au checkout
add_filter( 'wc_add_to_cart_message_html', '__return_false' );

/**
 * Coworking - Admin Columns
 */
/**
 * 1. Définir les colonnes du tableau admin "Réservations Coworking"
 */
add_filter('manage_cw_reservation_posts_columns', function($columns) {
    // On garde la case à cocher
    $new_columns = ['cb' => $columns['cb']];
    
    // On ajoute nos colonnes perso
    $new_columns['client']  = 'Client';
    $new_columns['dates']   = 'Dates';
    $new_columns['formule'] = 'Formule';
    $new_columns['offre']   = 'Espace';
    $new_columns['status']  = 'État'; // Publié = Confirmé
    
    // On remet la date de création à la fin
    $new_columns['date'] = 'Créée le';
    
    return $new_columns;
});

/**
 * 2. Remplir les données pour chaque colonne
 */
add_action('manage_cw_reservation_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'client':
            $name = get_post_meta($post_id, '_cw_customer_name', true);
            echo '<strong>' . esc_html($name ?: 'Inconnu') . '</strong>';
            break;

        case 'dates':
            $start = get_post_meta($post_id, '_cw_start', true);
            $end   = get_post_meta($post_id, '_cw_end', true);
            if ($start) {
                echo 'Du ' . date_i18n('d/m', strtotime($start));
                if ($start !== $end) {
                    echo ' au ' . date_i18n('d/m/Y', strtotime($end));
                } else {
                    echo ' (1 jour)';
                }
            }
            break;

        case 'formule':
            $f = get_post_meta($post_id, '_cw_formule', true);
            // Petit badge de couleur selon la formule
            $color = ($f === 'journee') ? '#e0f2fe;color:#0369a1' : (($f === 'semaine') ? '#f0fdf4;color:#15803d' : '#fefce8;color:#a16207');
            echo '<span style="background:'.$color.';padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;">'.esc_html($f).'</span>';
            break;

        case 'offre':
            echo esc_html(get_post_meta($post_id, '_cw_offre_name', true));
            break;
            
        case 'status':
            // Si le post est publié, c'est confirmé (payé)
            if (get_post_status($post_id) === 'publish') {
                echo '<span style="color:#10b981;font-weight:bold;">✅ Payé & Validé</span>';
            } else {
                echo '<span style="color:#ef4444;">En attente</span>';
            }
            break;
    }
}, 10, 2);

/**
 * 3. Rendre les colonnes triables (Optionnel mais pro)
 */
add_filter('manage_edit-cw_reservation_sortable_columns', function($columns) {
    $columns['dates'] = 'dates';
    return $columns;
});

/**
 * Widget Dashboard : "Arrivées du jour"
 */
add_action('wp_dashboard_setup', function() {
    add_meta_box(
        'cw_dashboard_widget_today',
        '📅 Coworking : Arrivées du jour',
        'cw_render_dashboard_widget',
        'dashboard',
        'side',
        'high'
    );
});

function cw_render_dashboard_widget() {
    $today = date('Y-m-d');
    
    // On cherche les résas qui incluent aujourd'hui
    // Note : C'est une requête simplifiée, idéalement on compare start <= today <= end
    // Ici on va chercher ceux qui COMMENCENT aujourd'hui pour faire simple, ou utiliser une meta_query plus complexe
    $args = [
        'post_type' => 'cw_reservation',
        'post_status' => 'publish',
        'posts_per_page' => 10,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_cw_start',
                'value' => $today,
                'compare' => '<=',
                'type' => 'DATE'
            ],
            [
                'key' => '_cw_end',
                'value' => $today,
                'compare' => '>=',
                'type' => 'DATE'
            ]
        ]
    ];
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        echo '<ul style="margin:0;padding:0;">';
        while ($query->have_posts()) {
            $query->the_post();
            $client = get_post_meta(get_the_ID(), '_cw_customer_name', true);
            $offre  = get_post_meta(get_the_ID(), '_cw_offre_name', true);
            echo '<li style="padding:8px 0;border-bottom:1px solid #eee;">';
            echo '<strong>👤 ' . esc_html($client) . '</strong><br>';
            echo '<span style="color:#666;font-size:12px;">📍 ' . esc_html($offre) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<p style="text-align:right;margin-top:10px;"><a href="edit.php?post_type=cw_reservation">Voir tout →</a></p>';
    } else {
        echo '<p style="color:#666;">Aucune arrivée prévue aujourd\'hui.</p>';
    }
    wp_reset_postdata();
}

/**
 * RGPD - Consentement Checkout
 */
/**
 * RGPD - Checkbox de consentement au checkout
 */

// Afficher la checkbox avant le bouton "Commander"
add_action('woocommerce_review_order_before_submit', function() {
    echo '<div class="rgpd-consent-wrapper" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #0073aa; border-radius: 4px;">
        <p class="form-row validate-required" style="margin: 0;">
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="cw_rgpd_consent" id="cw_rgpd_consent" required style="margin-top: 4px; flex-shrink: 0;" />
                <span style="font-size: 14px; line-height: 1.5;">
                    J\'accepte que mes données personnelles soient utilisées pour gérer ma réservation conformément à la 
                    <a href="/politique-confidentialite" target="_blank" style="color: #0073aa; text-decoration: underline;">politique de confidentialité</a>.
                    <abbr class="required" title="obligatoire" style="color: red; text-decoration: none;">*</abbr>
                </span>
            </label>
        </p>
    </div>';
});

// Validation côté serveur (sécurité)
add_action('woocommerce_checkout_process', function() {
    if (empty($_POST['cw_rgpd_consent'])) {
        wc_add_notice('Vous devez accepter la politique de confidentialité pour continuer.', 'error');
    }
});

// Enregistrer le consentement dans la commande (preuve légale)
add_action('woocommerce_checkout_update_order_meta', function($order_id) {
    if (!empty($_POST['cw_rgpd_consent'])) {
        update_post_meta($order_id, '_cw_rgpd_consent', 'yes');
        update_post_meta($order_id, '_cw_rgpd_consent_date', current_time('mysql'));
        update_post_meta($order_id, '_cw_rgpd_consent_ip', $_SERVER['REMOTE_ADDR']);
    }
});

/**
 * Page Planning Coworking - Version Minimale
 */
/**
 * SNIPPET 2 : Page Planning Coworking - VERSION COMPLÈTE
 * Créez un NOUVEAU snippet avec ce code
 * Nom suggéré : "Coworking - Page Planning Admin"
 */

/* ============================================================
   1. CRÉER LE MENU ADMIN
============================================================ */

add_action('admin_menu', function() {
    add_menu_page(
        'Planning Coworking',
        '📅 Planning',
        'manage_options',
        'cw-planning',
        'cw_render_full_planning_page',
        'dashicons-calendar-alt',
        3 // Position sous Dashboard
    );
});

/* ============================================================
   2. PAGE PRINCIPALE (TOUTES LES SECTIONS)
============================================================ */

function cw_render_full_planning_page() {
    ?>
    <div class="wrap">
        <h1 style="margin-bottom:30px;">📅 Planning des Réservations Coworking</h1>
        
        <!-- SECTION 1 : Arrivées du jour -->
        <div class="cw-section">
            <h2>🔥 Arrivées du jour</h2>
            <?php cw_render_today_arrivals(); ?>
        </div>
        
        <!-- SECTION 2 : Locks actifs -->
        <div class="cw-section">
            <h2>🔒 Paniers en cours (Locks temporaires)</h2>
            <?php cw_render_smart_locks_display(); ?>
        </div>
        
        <!-- SECTION 3 : Prochaines réservations (7j) -->
        <div class="cw-section">
            <h2>📆 Prochaines réservations (7 jours)</h2>
            <?php cw_render_upcoming_week_table(); ?>
        </div>
        
        <!-- SECTION 4 : Vue calendrier mensuelle -->
        <div class="cw-section">
            <h2>📅 Calendrier du mois</h2>
            <?php cw_render_monthly_calendar(); ?>
        </div>
        
        <!-- SECTION 5 : Blocages manuels -->
        <div class="cw-section">
            <h2>🚫 Gérer les dates bloquées</h2>
            <?php cw_render_manual_blocks_manager(); ?>
        </div>
    </div>
    
    <style>
        .cw-section {
            background: #fff;
            padding: 25px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .cw-section h2 {
            margin-top: 0;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
            color: #23282d;
        }
        .cw-empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .cw-stat-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        .cw-stat-badge.green { background: #d4edda; color: #155724; }
        .cw-stat-badge.yellow { background: #fff3cd; color: #856404; }
        .cw-stat-badge.red { background: #f8d7da; color: #721c24; }
    </style>
    <?php
}

/* ============================================================
   SECTION 1 : Arrivées du jour
============================================================ */

function cw_render_today_arrivals() {
    $today = date('Y-m-d');
    
    $args = [
        'post_type' => 'cw_reservation',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_cw_start',
                'value' => $today,
                'compare' => '<=',
                'type' => 'DATE'
            ],
            [
                'key' => '_cw_end',
                'value' => $today,
                'compare' => '>=',
                'type' => 'DATE'
            ]
        ]
    ];
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        echo '<div class="cw-empty-state">';
        echo '<p style="color:#10b981;font-weight:600;font-size:16px;">✅ Aucune arrivée aujourd\'hui</p>';
        echo '<p style="font-size:13px;">Les prochaines arrivées sont affichées dans la section "7 jours" ci-dessous.</p>';
        echo '</div>';
        return;
    }
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:200px;">Client</th>';
    echo '<th>Espace</th>';
    echo '<th style="width:180px;">Période</th>';
    echo '<th style="width:220px;">Contact</th>';
    echo '<th style="width:120px;">Actions</th>';
    echo '</tr></thead><tbody>';
    
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        
        $client = get_post_meta($post_id, '_cw_customer_name', true);
        $email = get_post_meta($post_id, '_cw_customer_email', true);
        $offre = get_post_meta($post_id, '_cw_offre_name', true);
        $start = get_post_meta($post_id, '_cw_start', true);
        $end = get_post_meta($post_id, '_cw_end', true);
        $order_id = get_post_meta($post_id, '_cw_order_id', true);
        
        echo '<tr>';
        echo '<td><strong>' . esc_html($client) . '</strong></td>';
        echo '<td>' . esc_html($offre) . '</td>';
        echo '<td>' . date_i18n('d/m', strtotime($start)) . ' → ' . date_i18n('d/m/Y', strtotime($end)) . '</td>';
        echo '<td><a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></td>';
        echo '<td>';
        if ($order_id) {
            echo '<a href="' . admin_url('post.php?post=' . $order_id . '&action=edit') . '" class="button button-small">Commande</a>';
        }
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    wp_reset_postdata();
}

/* ============================================================
   SECTION 2 : Locks avec distinction Strict/Flexible
============================================================ */

function cw_render_smart_locks_display() {
    global $wpdb;
    
    $locks_data = $wpdb->get_results("
        SELECT option_name, option_value 
        FROM $wpdb->options 
        WHERE option_name LIKE '_transient_cw_locks_%'
    ");
    
    if (empty($locks_data)) {
        echo '<div class="cw-empty-state">';
        echo '<p style="color:#10b981;font-weight:600;font-size:16px;">✅ Aucun panier en cours</p>';
        echo '<p style="font-size:13px;">Toutes les dates "indisponibles" affichées sur le site sont de vraies réservations payées.</p>';
        echo '</div>';
        return;
    }
    
    $now = time();
    $locks_by_type = ['strict' => [], 'flexible' => []];
    
    foreach ($locks_data as $row) {
        preg_match('/cw_locks_(\d+)/', $row->option_name, $matches);
        $offre_id = isset($matches[1]) ? intval($matches[1]) : 0;
        if (!$offre_id) continue;
        
        $locks = maybe_unserialize($row->option_value);
        if (!is_array($locks)) continue;
        
        foreach ($locks as $lock) {
            if (!isset($lock['expires_at']) || $lock['expires_at'] <= $now) continue;
            
            $lock_info = [
                'offre_id' => $offre_id,
                'offre_name' => get_the_title($offre_id),
                'start' => $lock['start'],
                'end' => $lock['end'],
                'token' => $lock['token'] ?? '',
                'expires_at' => $lock['expires_at'],
                'time_left' => $lock['expires_at'] - $now,
                'capacity' => (int) get_field('capacite_max', $offre_id)
            ];
            
            $type = ($lock['lock_type'] ?? 'flexible');
            $locks_by_type[$type][] = $lock_info;
        }
    }
    
    // Afficher STRICTS en priorité
    if (!empty($locks_by_type['strict'])) {
        echo '<div style="background:#ffe5e5;padding:15px;border-left:4px solid #dc3545;margin-bottom:20px;border-radius:4px;">';
        echo '<h3 style="margin:0 0 8px 0;color:#dc3545;font-size:15px;">🔒 Locks prioritaires (Salle de réunion - 20 min)</h3>';
        echo '<p style="margin:0;font-size:12px;color:#666;">Ces créneaux sont bloqués longtemps car la capacité est limitée à 1 espace.</p>';
        echo '</div>';
        cw_render_locks_table_html($locks_by_type['strict'], '#ffe5e5');
    }
    
    // Afficher FLEXIBLES
    if (!empty($locks_by_type['flexible'])) {
        echo '<div style="background:#fff3cd;padding:15px;border-left:4px solid #ffc107;margin-bottom:20px;border-radius:4px;margin-top:' . (empty($locks_by_type['strict']) ? '0' : '20px') . ';">';
        echo '<h3 style="margin:0 0 8px 0;color:#856404;font-size:15px;">⏱️ Locks souples (Bureaux privés - 5 min)</h3>';
        echo '<p style="margin:0;font-size:12px;color:#666;">Ces créneaux sont bloqués brièvement (stock disponible : 7 bureaux).</p>';
        echo '</div>';
        cw_render_locks_table_html($locks_by_type['flexible'], '#fff3cd');
    }
    
    if (empty($locks_by_type['strict']) && empty($locks_by_type['flexible'])) {
        echo '<p style="color:#10b981;font-weight:600;">✅ Tous les locks ont expiré</p>';
    }
}

function cw_render_locks_table_html($locks, $bg_color) {
    usort($locks, fn($a, $b) => $a['time_left'] - $b['time_left']);
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Espace</th><th>Dates verrouillées</th><th>Expire dans</th><th>Action</th></tr></thead><tbody>';
    
    foreach ($locks as $lock) {
        $minutes = ceil($lock['time_left'] / 60);
        $urgency = ($minutes <= 3) ? 'color:#dc3545;font-weight:bold;' : '';
        
        echo '<tr style="background:' . $bg_color . ';">';
        echo '<td><strong>' . esc_html($lock['offre_name']) . '</strong>';
        echo '<br><small style="color:#666;">Stock : ' . $lock['capacity'] . '</small></td>';
        echo '<td>' . date('d/m/Y', strtotime($lock['start']));
        if ($lock['start'] !== $lock['end']) {
            echo ' → ' . date('d/m/Y', strtotime($lock['end']));
        }
        echo '</td>';
        echo '<td><span style="' . $urgency . '">⏱️ ' . $minutes . ' min</span></td>';
        echo '<td>';
        echo '<button class="button button-small cw-force-unlock" ';
        echo 'data-offre="' . $lock['offre_id'] . '" ';
        echo 'data-token="' . esc_attr($lock['token']) . '">';
        echo '🔓 Libérer';
        echo '</button>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Script pour forcer libération
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.cw-force-unlock').off('click').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Libérer ce créneau maintenant ?\n\nLe client en cours de paiement perdra sa réservation.')) return;
            
            var btn = $(this);
            var offre = btn.data('offre');
            var token = btn.data('token');
            
            btn.prop('disabled', true).text('⏳ Libération...');
            
            $.post(ajaxurl, {
                action: 'cw_force_unlock_manual',
                offre_id: offre,
                token: token
            }, function(response) {
                if (response.success) {
                    btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                    alert('✅ Lock libéré avec succès');
                } else {
                    alert('❌ Erreur : ' + response.data);
                    btn.prop('disabled', false).text('🔓 Libérer');
                }
            });
        });
    });
    </script>
    <?php
}

// Handler AJAX libération manuelle
add_action('wp_ajax_cw_force_unlock_manual', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Non autorisé');
    }
    
    $offre_id = intval($_POST['offre_id'] ?? 0);
    $token = sanitize_text_field($_POST['token'] ?? '');
    
    if (!$offre_id || !$token) {
        wp_send_json_error('Paramètres manquants');
    }
    
    if (function_exists('coworking_remove_lock_by_token')) {
        coworking_remove_lock_by_token($offre_id, $token);
        wp_send_json_success('Lock libéré');
    } else {
        wp_send_json_error('Fonction introuvable');
    }
});

/* ============================================================
   SECTION 3 : Prochaines réservations (7 jours)
============================================================ */

function cw_render_upcoming_week_table() {
    $today = date('Y-m-d');
    $next_week = date('Y-m-d', strtotime('+7 days'));
    
    $args = [
        'post_type' => 'cw_reservation',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_key' => '_cw_start',
        'orderby' => 'meta_value',
        'order' => 'ASC',
        'meta_query' => [
            [
                'key' => '_cw_start',
                'value' => [$today, $next_week],
                'compare' => 'BETWEEN',
                'type' => 'DATE'
            ]
        ]
    ];
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        echo '<div class="cw-empty-state">';
        echo '<p>Aucune réservation dans les 7 prochains jours</p>';
        echo '</div>';
        return;
    }
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:120px;">Date</th>';
    echo '<th>Client</th>';
    echo '<th>Espace</th>';
    echo '<th style="width:100px;">Formule</th>';
    echo '<th style="width:80px;">Prix</th>';
    echo '</tr></thead><tbody>';
    
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        
        $client = get_post_meta($post_id, '_cw_customer_name', true);
        $offre = get_post_meta($post_id, '_cw_offre_name', true);
        $start = get_post_meta($post_id, '_cw_start', true);
        $end = get_post_meta($post_id, '_cw_end', true);
        $formule = get_post_meta($post_id, '_cw_formule', true);
        $price = get_post_meta($post_id, '_cw_price', true);
        
        // Code couleur selon proximité
        $days_until = (strtotime($start) - time()) / 86400;
        $row_style = '';
        if ($days_until < 1) {
            $row_style = 'background:#ffe5e5;'; // Rouge
        } elseif ($days_until < 3) {
            $row_style = 'background:#fff3cd;'; // Jaune
        }
        
        echo '<tr style="' . $row_style . '">';
        echo '<td><strong>' . date_i18n('d/m/Y', strtotime($start)) . '</strong>';
        if ($start !== $end) {
            echo '<br><small style="color:#666;">→ ' . date_i18n('d/m/Y', strtotime($end)) . '</small>';
        }
        echo '</td>';
        echo '<td>' . esc_html($client) . '</td>';
        echo '<td>' . esc_html($offre) . '</td>';
        echo '<td><span style="font-size:11px;text-transform:uppercase;color:#666;">' . esc_html($formule) . '</span></td>';
        echo '<td><strong>' . wc_price($price) . '</strong></td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    wp_reset_postdata();
}

/* ============================================================
   SECTION 4 : Vue calendrier mensuelle (simplifié)
============================================================ */

function cw_render_monthly_calendar() {
    $current_month = date('Y-m');
    
    // Récupérer toutes les offres
    $offres = get_posts([
        'post_type' => 'offre-coworking',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ]);
    
    if (empty($offres)) {
        echo '<p>Aucune offre configurée</p>';
        return;
    }
    
    echo '<div style="margin-bottom:20px;">';
    echo '<label style="font-weight:600;margin-right:10px;">Espace :</label>';
    echo '<select id="cw-cal-offre" style="padding:6px 12px;border-radius:4px;">';
    foreach ($offres as $offre) {
        echo '<option value="' . $offre->ID . '">' . esc_html($offre->post_title) . '</option>';
    }
    echo '</select>';
    
    echo '<label style="font-weight:600;margin-left:20px;margin-right:10px;">Mois :</label>';
    echo '<input type="month" id="cw-cal-month" value="' . $current_month . '" style="padding:6px 12px;border-radius:4px;">';
    
    echo '<button type="button" class="button button-primary" id="cw-cal-refresh" style="margin-left:10px;">🔄 Actualiser</button>';
    echo '</div>';
    
    echo '<div id="cw-calendar-display" style="min-height:300px;"></div>';
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        function loadCalendar() {
            const offre = $('#cw-cal-offre').val();
            const month = $('#cw-cal-month').val();
            
            $('#cw-calendar-display').html('<p style="text-align:center;padding:40px;">⏳ Chargement...</p>');
            
            $.get('<?php echo home_url('/wp-json/coworking/v1/availability/'); ?>' + offre + '?month=' + month, function(data) {
                if (!data.success) {
                    $('#cw-calendar-display').html('<p style="color:red;">Erreur de chargement</p>');
                    return;
                }
                
                let html = '<table class="wp-list-table widefat fixed striped" style="table-layout:fixed;">';
                html += '<thead><tr><th>Date</th><th>Statut</th><th>Places disponibles</th></tr></thead><tbody>';
                
                const availability = data.availability;
                for (const date in availability) {
                    const info = availability[date];
                    let statusLabel = '';
                    let statusColor = '';
                    
                    if (info.status === 'available') {
                        statusLabel = '✅ Disponible';
                        statusColor = '#d4edda';
                    } else if (info.status === 'low') {
                        statusLabel = '⚠️ Stock faible';
                        statusColor = '#fff3cd';
                    } else if (info.status === 'full') {
                        statusLabel = '🔴 Complet';
                        statusColor = '#f8d7da';
                    } else {
                        statusLabel = '❌ Indisponible';
                        statusColor = '#e2e8f0';
                    }
                    
                    html += '<tr style="background:' + statusColor + ';">';
                    html += '<td><strong>' + new Date(date + 'T12:00:00').toLocaleDateString('fr-FR') + '</strong></td>';
                    html += '<td>' + statusLabel + '</td>';
                    html += '<td><strong>' + info.slots + '</strong> / ' + info.capacity + '</td>';
                    html += '</tr>';
                }
                
                html += '</tbody></table>';
                $('#cw-calendar-display').html(html);
            });
        }
        
        $('#cw-cal-refresh').on('click', loadCalendar);
        loadCalendar(); // Charger au démarrage
    });
    </script>
    <?php
}

/* ============================================================
   SECTION 5 : Blocages manuels
============================================================ */

function cw_render_manual_blocks_manager() {
    $offres = get_posts([
        'post_type' => 'offre-coworking',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ]);
    
    // Formulaire d'ajout
    echo '<form method="post" action="" style="background:#f8f9fa;padding:20px;border-radius:6px;margin-bottom:25px;">';
    wp_nonce_field('cw_add_block', 'cw_block_nonce');
    
    echo '<div style="display:grid;grid-template-columns:1fr 1fr 2fr auto;gap:15px;align-items:end;">';
    
    echo '<div>';
    echo '<label style="display:block;margin-bottom:5px;font-weight:600;">Espace</label>';
    echo '<select name="cw_block_offre" required style="width:100%;padding:8px;">';
    echo '<option value="">Choisir...</option>';
    foreach ($offres as $offre) {
        echo '<option value="' . $offre->ID . '">' . esc_html($offre->post_title) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    
    echo '<div>';
    echo '<label style="display:block;margin-bottom:5px;font-weight:600;">Date</label>';
    echo '<input type="date" name="cw_block_date" required min="' . date('Y-m-d') . '" style="width:100%;padding:8px;">';
    echo '</div>';
    
    echo '<div>';
    echo '<label style="display:block;margin-bottom:5px;font-weight:600;">Raison (optionnel)</label>';
    echo '<input type="text" name="cw_block_reason" placeholder="Ex: Maintenance, Fermeture..." style="width:100%;padding:8px;">';
    echo '</div>';
    
    echo '<div>';
    echo '<button type="submit" name="cw_add_block_submit" class="button button-primary" style="padding:8px 20px;">🚫 Bloquer</button>';
    echo '</div>';
    
    echo '</div>';
    echo '</form>';
    
    // Traitement
    if (isset($_POST['cw_add_block_submit']) && check_admin_referer('cw_add_block', 'cw_block_nonce')) {
        $offre_id = intval($_POST['cw_block_offre']);
        $date = sanitize_text_field($_POST['cw_block_date']);
        $reason = sanitize_text_field($_POST['cw_block_reason']);
        
        $current = get_field('dates_indisponibles_manuel', $offre_id) ?: '';
        $new_line = $date . ($reason ? ' # ' . $reason : '');
        $updated = trim($current . "\n" . $new_line);
        
        update_field('dates_indisponibles_manuel', $updated, $offre_id);
        
        echo '<div class="notice notice-success is-dismissible" style="margin:15px 0;"><p>✅ Date bloquée avec succès</p></div>';
    }
    
    // Afficher blocages existants
    echo '<h3>Dates actuellement bloquées</h3>';
    
    $has_blocks = false;
    foreach ($offres as $offre) {
        $blocks = get_field('dates_indisponibles_manuel', $offre->ID) ?: '';
        if (!$blocks) continue;
        
        $lines = array_filter(explode("\n", $blocks));
        if (empty($lines)) continue;
        
        $has_blocks = true;
        
        echo '<h4 style="margin-top:20px;color:#2271b1;">' . esc_html($offre->post_title) . '</h4>';
        echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom:15px;">';
        echo '<thead><tr><th style="width:150px;">Date</th><th>Raison</th><th style="width:100px;">Action</th></tr></thead><tbody>';
        
        foreach ($lines as $line) {
            $parts = explode('#', $line);
            $date = trim($parts[0]);
            $reason = isset($parts[1]) ? trim($parts[1]) : '—';
            
            echo '<tr>';
            echo '<td><strong>' . date_i18n('d/m/Y', strtotime($date)) . '</strong></td>';
            echo '<td>' . esc_html($reason) . '</td>';
            echo '<td><span class="cw-unblock" data-offre="' . $offre->ID . '" data-date="' . $date . '" style="cursor:pointer;color:#dc3545;">❌ Débloquer</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    if (!$has_blocks) {
        echo '<p style="color:#666;text-align:center;padding:20px;">Aucune date bloquée manuellement</p>';
    }
}
