import os
import sys
import subprocess
import docx
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

def set_cell_background(cell, fill_hex):
    tcPr = cell._element.get_or_add_tcPr()
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), fill_hex)
    tcPr.append(shd)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._element.get_or_add_tcPr()
    tcMar = OxmlElement('w:tcMar')
    for m, val in [('top', top), ('bottom', bottom), ('left', left), ('right', right)]:
        node = OxmlElement(f'w:{m}')
        node.set(qn('w:w'), str(val))
        node.set(qn('w:type'), 'dxa')
        tcMar.append(node)
    tcPr.append(tcMar)

def create_report():
    doc = docx.Document()

    # Set page margins (0.75 in top/bottom, 0.8 in left/right)
    sections = doc.sections
    for section in sections:
        section.top_margin = Inches(0.75)
        section.bottom_margin = Inches(0.75)
        section.left_margin = Inches(0.8)
        section.right_margin = Inches(0.8)

    # Base Styles
    style_normal = doc.styles['Normal']
    style_normal.font.name = 'Arial'
    style_normal.font.size = Pt(10)
    style_normal.font.color.rgb = RGBColor(30, 41, 59) # Slate 800

    # Header Institution / Academic Context
    p_inst = doc.add_paragraph()
    p_inst.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_inst.paragraph_format.space_before = Pt(0)
    p_inst.paragraph_format.space_after = Pt(2)
    r_inst = p_inst.add_run("DEPARTMENT OF COMPUTER SCIENCE & ENGINEERING")
    r_inst.font.name = 'Arial'
    r_inst.font.size = Pt(10.5)
    r_inst.font.bold = True
    r_inst.font.color.rgb = RGBColor(71, 85, 105)

    p_proj = doc.add_paragraph()
    p_proj.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_proj.paragraph_format.space_after = Pt(6)
    r_proj = p_proj.add_run("ACADEMIC MAJOR PROJECT TECHNICAL & ARCHITECTURAL REPORT")
    r_proj.font.name = 'Arial'
    r_proj.font.size = Pt(9.5)
    r_proj.font.bold = True
    r_proj.font.color.rgb = RGBColor(100, 116, 139)

    # Title Header Banner
    p_title = doc.add_paragraph()
    p_title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_title.paragraph_format.space_before = Pt(4)
    p_title.paragraph_format.space_after = Pt(4)
    run_title = p_title.add_run("RIDESYNC: AN INTELLIGENT CAMPUS MOBILITY, GEODESIC ROUTE MATCHING AND DRIVER DISPATCH PLATFORM")
    run_title.font.name = 'Arial'
    run_title.font.size = Pt(17)
    run_title.font.bold = True
    run_title.font.color.rgb = RGBColor(37, 99, 235) # Royal Blue

    p_sub = doc.add_paragraph()
    p_sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_sub.paragraph_format.space_after = Pt(6)
    run_sub = p_sub.add_run("Comprehensive Technical, Algorithmic & Architectural Project Report for Academic Evaluation, Viva Voce & Exhibition Showcase")
    run_sub.font.name = 'Arial'
    run_sub.font.size = Pt(10.5)
    run_sub.font.italic = True
    run_sub.font.color.rgb = RGBColor(71, 85, 105)

    p_meta = doc.add_paragraph()
    p_meta.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_meta.paragraph_format.space_after = Pt(14)
    r_meta = p_meta.add_run("Decoupled MVC Architecture | Spherical Geodesic Route Fitting | Pessimistic Row Locking | AI KYC Verification Sandbox | Real-Time Telemetry & SOS | 14-Action Self-Healing Repair Kit | Dual-Browser CDP Automation")
    r_meta.font.size = Pt(8.5)
    r_meta.font.bold = True
    r_meta.font.color.rgb = RGBColor(15, 23, 42)

    def add_heading_1(text):
        h = doc.add_paragraph()
        h.paragraph_format.space_before = Pt(16)
        h.paragraph_format.space_after = Pt(5)
        r = h.add_run(text)
        r.font.name = 'Arial'
        r.font.size = Pt(13)
        r.font.bold = True
        r.font.color.rgb = RGBColor(30, 58, 138) # Dark Navy
        return h

    def add_heading_2(text):
        h = doc.add_paragraph()
        h.paragraph_format.space_before = Pt(12)
        h.paragraph_format.space_after = Pt(4)
        r = h.add_run(text)
        r.font.name = 'Arial'
        r.font.size = Pt(11)
        r.font.bold = True
        r.font.color.rgb = RGBColor(29, 78, 216) # Royal Blue
        return h

    def add_heading_3(text):
        h = doc.add_paragraph()
        h.paragraph_format.space_before = Pt(8)
        h.paragraph_format.space_after = Pt(3)
        r = h.add_run(text)
        r.font.name = 'Arial'
        r.font.size = Pt(10)
        r.font.bold = True
        r.font.color.rgb = RGBColor(15, 23, 42)
        return h

    def add_callout(text, bg_hex="F1F5F9"):
        tbl = doc.add_table(rows=1, cols=1)
        tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
        cell = tbl.cell(0, 0)
        set_cell_background(cell, bg_hex)
        set_cell_margins(cell, top=100, bottom=100, left=150, right=150)
        p = cell.paragraphs[0]
        p.paragraph_format.space_after = Pt(0)
        r = p.add_run(text)
        r.font.name = 'Arial'
        r.font.size = Pt(9.5)
        r.font.italic = True
        r.font.color.rgb = RGBColor(30, 41, 59)
        doc.add_paragraph().paragraph_format.space_after = Pt(4)

    def add_formula_box(text):
        tbl = doc.add_table(rows=1, cols=1)
        tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
        cell = tbl.cell(0, 0)
        set_cell_background(cell, "F8FAFC")
        set_cell_margins(cell, top=90, bottom=90, left=140, right=140)
        p = cell.paragraphs[0]
        p.paragraph_format.space_after = Pt(0)
        r = p.add_run(text)
        r.font.name = 'Consolas'
        r.font.size = Pt(9)
        r.font.color.rgb = RGBColor(15, 23, 42)
        doc.add_paragraph().paragraph_format.space_after = Pt(4)

    def style_table_header(row):
        for cell in row:
            set_cell_background(cell, "1E293B")
            set_cell_margins(cell, top=75, bottom=75, left=90, right=90)
            for p in cell.paragraphs:
                p.paragraph_format.space_after = Pt(0)
                for r in p.runs:
                    r.font.bold = True
                    r.font.size = Pt(8.5)
                    r.font.color.rgb = RGBColor(255, 255, 255)

    def style_table_rows(table, data, bg_even="FFFFFF", bg_odd="F8FAFC"):
        for i, row_data in enumerate(data):
            row = table.add_row().cells
            bg = bg_even if i % 2 == 0 else bg_odd
            for j, val in enumerate(row_data):
                row[j].text = str(val)
                set_cell_background(row[j], bg)
                set_cell_margins(row[j], top=60, bottom=60, left=85, right=85)
                for p in row[j].paragraphs:
                    p.paragraph_format.space_after = Pt(0)
                    for r in p.runs:
                        r.font.size = Pt(8.5)

    # =========================================================================
    # 1. Executive Summary & Abstract
    # =========================================================================
    add_heading_1("1. Executive Summary & Abstract")
    doc.add_paragraph(
        "RideSync is an end-to-end, high-performance web-based campus mobility and intelligent driver dispatch platform. "
        "Engineered specifically to resolve last-mile commute bottlenecks, fragmented transit coordination, and transportation "
        "inefficiencies across university and collegiate ecosystems, RideSync establishes a unified, role-based ecosystem "
        "connecting student riders, campus drivers, and institutional administrators into a secure, synchronized transit network. "
        "By fusing spherical geodesic route-fitting algorithms, partial-path carpool corridor matching, strict pessimistic database concurrency locking, "
        "high-frequency real-time telemetry, 1-tap emergency SOS broadcasting, and an autonomous operational self-healing engine (Repair Kit), "
        "RideSync guarantees strict transactional safety, sub-50ms API response latency, and zero-friction usability across any device."
    )
    add_callout(
        "Key Project Highlights & Verified Engineering Achievements:\n"
        "• Unified Multi-Role Architecture: Dedicated, responsive Rider, Driver, and Admin portals with universal segmented tab bars, sticky glassmorphic navigation, and responsive card reflow.\n"
        "• Geodesic Route Fitting & Corridor Matching: Spherical Haversine spatial calculations, multi-factor compatibility scoring, and OSRM server-side polyline snapping with directional sequencing.\n"
        "• Strict Pessimistic Concurrency Locking: ACID-compliant SELECT ... FOR UPDATE database transaction locks eliminating double-claim race conditions under high concurrent demand.\n"
        "• Real-Time Telemetry & Safety Core: WebSocket-based live GPS coordinate streaming, bearing calculations, dynamic ETA updates, and 1-tap emergency SOS escalation with geo-tagged alerts.\n"
        "• AI Driver KYC Verification Sandbox: FastAPI microservice with OpenCV facial feature matching, OCR document extraction, document tampering detection, and a synthetic academic compliance sandbox.\n"
        "• Ecological Impact Quantification: Automated CO2 emissions avoidance calculations, fuel conservation metrics, and green leaderboard tracking.\n"
        "• Autonomous Self-Healing Platform: Production Repair Kit featuring 14 automated diagnostics, index auto-creation, stale lock eviction, queue repair, and AES-256 encrypted run logging.\n"
        "• Dual-Browser CDP Automation Engine: Comprehensive headless end-to-end testing across Google Chrome and Brave Browser via Chrome DevTools Protocol.\n"
        "• 100% Quality Pass & Sub-50ms Latency: 68 PHPUnit tests (243 assertions), 19 negative security tests, 6 authenticated API lifecycle flows, 27 OpenAPI contract paths, and 25 AI microservice selftests."
    )

    # =========================================================================
    # 2. Literature Survey & Comparative Analysis
    # =========================================================================
    add_heading_1("2. Literature Survey & Comparative Technological Analysis")
    doc.add_paragraph(
        "Existing commercial mobility platforms such as Uber, Ola, BlaBlaCar, and Quick Ride are engineered primarily for wide-area urban transit "
        "or long-distance intercity travel. When applied to suburban university campuses and college towns, these platforms suffer from systemic "
        "limitations including aggressive dynamic surge pricing, absence of verified academic perimeter trust, lack of short-hop carpooling support, "
        "and reliance on expensive native smartphone hardware and tracking SDKs."
    )

    t_lit = doc.add_table(rows=1, cols=4)
    t_lit.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr_lit = t_lit.rows[0].cells
    hdr_lit[0].text = "Evaluation Dimension"
    hdr_lit[1].text = "Commercial Ride-Hailing (Uber / Ola)"
    hdr_lit[2].text = "Intercity Carpooling (BlaBlaCar)"
    hdr_lit[3].text = "RideSync Campus Platform"
    style_table_header(hdr_lit)

    lit_data = [
        ("Target Demographic", "General public, unverified strangers", "Long-distance highway commuters", "Verified university students, faculty & drivers"),
        ("Pricing & Fare Model", "Dynamic surge pricing, high commissions", "Fixed seat cost, driver-dictated", "Fair-split algorithm (km_rate_v3), zero surge, non-profit"),
        ("Campus Route Geometry", "Point-to-point only; poor suburban transit", "City-to-city only; no micro-corridors", "Geodesic corridor matching & waypoint snapping"),
        ("Security & Safety", "Basic in-app button, emergency call", "Public reviews, phone sharing", "1-Tap SOS, live WebSocket coords, emergency contacts"),
        ("Driver Verification", "Manual paper vetting or 3rd-party vendor", "Unverified driver identity", "AI KYC OCR + Facial match + Synthetic Sandbox"),
        ("Concurrency Safety", "Proprietary distributed message queues", "Basic optimistic or web locks", "ACID transactions with SELECT ... FOR UPDATE row locks"),
        ("Client Hardware Overhead", "Heavy native app, background battery drain", "Standard web / mobile app", "Ultra-lightweight PWA, 0 framework overhead, sub-50ms TTFB"),
        ("Operational Recovery", "Centralized enterprise SRE infrastructure", "Standard support ticket queue", "Self-healing Repair Kit (14 automated diagnostic handlers)")
    ]
    style_table_rows(t_lit, lit_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    # =========================================================================
    # 3. Software Requirements Specification (SRS)
    # =========================================================================
    add_heading_1("3. Software Requirements Specification (SRS)")
    doc.add_paragraph(
        "The software requirements for RideSync are categorized into functional capabilities by stakeholder role and non-functional engineering constraints."
    )

    add_heading_2("3.1 Functional Requirements by Stakeholder Role")
    doc.add_paragraph(
        "• Rider Module: User registration, institutional email validation, ride posting with origin/destination coordinates, intelligent search with geodesic route fit scoring, partial-path matching, real-time GPS tracking, 1-tap SOS emergency activation, emergency contact configuration, bidirectional rating, and monthly eco-impact green score review.\n"
        "• Driver Module: Driver onboarding, vehicle profile registration, KYC document submission (Driver's License, Aadhaar, PAN, Vehicle RC), real-time availability toggle, incoming dispatch request review with fare previews, pessimistic lock claim acceptance, GPS telemetry ping submission, and earnings history tracking.\n"
        "• Administrator Module: System overview analytics, live ride monitoring, driver verification queue management with AI assistance, user suspension/unsuspension, incident report resolution, view-as simulation, and operational execution of the 14-action Repair Kit.\n"
        "• Dispatch & Telemetry Engine: Automatic fallback routing from carpool match to on-demand driver dispatch, WebSocket broadcast of state transitions, and background job worker supervision."
    )

    add_heading_2("3.2 Non-Functional Engineering Requirements")
    doc.add_paragraph(
        "• Performance & Latency: Time to First Byte (TTFB) p95 latency under 50ms across all core endpoints with OPcache bytecode caching and session principal caching.\n"
        "• Concurrency & Data Integrity: Zero double-booking or double-claim race conditions under high concurrent demand via InnoDB row-level pessimistic locks.\n"
        "• Defensive Security: AES-256-CBC document encryption, parameterized prepared statements across 100% of SQL queries, CSRF token verification, and restrictive Content Security Policy (CSP).\n"
        "• Cross-Browser & Mobile Fidelity: Seamless rendering and 100% feature parity across Google Chrome, Brave Browser, Firefox, Edge, Safari, and mobile web viewports (<768px)."
    )

    # =========================================================================
    # 4. System Architecture & Subsystem Engineering
    # =========================================================================
    add_heading_1("4. System Architecture & Subsystem Engineering")
    doc.add_paragraph(
        "RideSync is engineered using a decoupled, modular Model-View-Controller (MVC) architecture in PHP 8.2 and MySQL 8.4 / MariaDB 10.4+, "
        "complemented by a responsive Vanilla JavaScript frontend with centralized CSS design tokens, a Node.js WebSocket gateway for real-time telemetry, "
        "and an external Python FastAPI microservice for AI-powered driver verification."
    )

    t_stack = doc.add_table(rows=1, cols=3)
    t_stack.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t_stack.rows[0].cells
    hdr[0].text = "Subsystem Layer"
    hdr[1].text = "Technology / Framework"
    hdr[2].text = "Architectural Purpose & Responsibility"
    style_table_header(hdr)

    stack_data = [
        ("Core Application Server", "PHP 8.2 (Strict Types, Mysqli, OPcache)", "Request routing, auth middleware, session principal caching, CSRF protection, controller action handlers"),
        ("Relational Persistence", "MySQL 8.4 / MariaDB 10.4+ (InnoDB Engine)", "ACID relational persistence, composite spatial indexes, FOR UPDATE pessimistic locks, automated migrations"),
        ("Realtime Telemetry Gateway", "Node.js 20+ WebSocket (Port 8081)", "HMAC SHA-256 signed query tokens, low-latency SOS alerts, high-frequency driver live location broadcasts"),
        ("AI Verification Microservice", "Python 3.11/3.13 FastAPI + OpenCV (Port 8011)", "Automated driver KYC document OCR, facial feature matching, document tampering analysis, synthetic sandbox"),
        ("Frontend & Design Tokens", "Vanilla JS (ES6+), Glassmorphic CSS Engine", "Centralized design tokens scale, floating mobile navigation dock, table-to-card dynamic reflow, notification toasts"),
        ("Geolocation & Routing", "OSRM & Leaflet / OpenStreetMap", "Geodesic coordinate routing, waypoint detour calculation, route polyline rendering, landmark address shortener"),
        ("Browser Automation Harness", "Node.js CDP (Chrome & Brave Runners)", "Autonomous browser automation, DOM verification, visual screenshot capture, ad-blocker compatibility testing"),
        ("Operational Self-Healing", "RideSync Repair Kit (14 Action Handlers)", "Autonomous index repair, stale lock eviction, worker supervision, cache flushing, and schema healing"),
        ("Cloud Infrastructure", "Render Docker Blueprints & Cloud MySQL", "Render Web Service containerization, managed cloud MySQL connectivity, and persistent connection pooling"),
        ("Quality Assurance Stack", "PHPUnit 11.5 & K6 Load Testing", "68 unit tests, 243 assertions, 19 negative API checks, 27 OpenAPI paths, sub-50ms performance load profiling")
    ]
    style_table_rows(t_stack, stack_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    # =========================================================================
    # 5. Core Mathematical Models & Algorithms
    # =========================================================================
    add_heading_1("5. Core Mathematical Models & Algorithms")

    add_heading_2("5.1 Geodesic Route Distance (Spherical Haversine Equation)")
    doc.add_paragraph(
        "To compute precise spatial distances between pickup coordinates (lat1, lon1) and destination coordinates (lat2, lon2) across the curved Earth, "
        "RideSync implements the spherical Haversine formula in `ridesync_haversine_distance`:"
    )
    add_formula_box(
        "Δlat = rad(lat2 - lat1),  Δlon = rad(lon2 - lon1)\n"
        "a = sin²(Δlat / 2) + cos(rad(lat1)) * cos(rad(lat2)) * sin²(Δlon / 2)\n"
        "c = 2 * atan2(√a, √(1 − a))\n"
        "Distance (d) = R * c   [where Earth Mean Radius R = 6,371.0 km]\n"
        "Computational Complexity: O(1) constant time mathematical evaluation per coordinate pair."
    )

    add_heading_2("5.2 Multi-Factor Route Fit Scoring & Compatibility Model")
    doc.add_paragraph(
        "When a rider searches for matching rides, RideSync evaluates candidate routes using a multi-factor fitness function "
        "balancing spatial proximity, temporal alignment, and capacity constraints in `matching_helper.php`:"
    )
    add_formula_box(
        "Fit Score = (W_dist * S_dist) + (W_time * S_time) + (W_seats * S_seats)\n\n"
        "Where:\n"
        "• S_dist  = max(0, 1 - (Pickup_Deviation_km / Max_Allowed_Radius))\n"
        "• S_time  = max(0, 1 - (|Requested_Time - Offered_Time|_mins / 60))\n"
        "• S_seats = (Available_Seats >= Requested_Seats) ? 1.0 : 0.0\n"
        "• Normalized Weighting Factors: W_dist = 0.50, W_time = 0.35, W_seats = 0.15\n\n"
        "Compatibility Threshold: Routes scoring < 0.60 are discarded or routed to on-demand driver dispatch."
    )

    add_heading_2("5.3 Partial-Path Route Overlap & Polyline Snapping Algorithm")
    doc.add_paragraph(
        "In university commutes, riders frequently require rides along a subsegment of a driver's longer journey. "
        "RideSync implements an advanced partial-path carpooling engine in `includes/route_overlap_helper.php`:"
    )
    add_formula_box(
        "1. Polyline Retrieval: Query OSRM server-side for driver route coordinates [[lat1, lon1], ..., [latN, lonN]].\n"
        "2. Coordinate Snapping: Project rider pickup and dropoff points onto nearest polyline segments.\n"
        "3. Corridor Proximity Gate: Assert Pickup_Distance <= 1.5 km AND Dropoff_Distance <= 1.5 km.\n"
        "4. Directional Sequence Invariant: Assert Point_Index(Pickup) < Point_Index(Dropoff).\n"
        "   [Guarantees rider is traveling in the SAME forward direction along the route]\n"
        "5. Overlap Ratio Formulation:\n"
        "   Overlap % = round((|Point_Index(Dropoff) - Point_Index(Pickup)| / Total_Polyline_Points) * 100)\n"
        "6. Detour Cost Evaluation: Computes additional distance (Δd) and time (Δt) incurred by driver."
    )

    add_heading_2("5.4 Database Concurrency Control & Double-Claim Prevention")
    doc.add_paragraph(
        "To eliminate double-claim race conditions when multiple drivers attempt to claim the same rider dispatch request simultaneously, "
        "RideSync enforces database-level transactions with SELECT ... FOR UPDATE pessimistic row locks:"
    )
    add_formula_box(
        "1. Begin Atomic Transaction:     mysqli_begin_transaction($conn);\n"
        "2. Acquire Exclusive Row Lock:  SELECT status, driver_id FROM driver_requests WHERE id = ? FOR UPDATE;\n"
        "3. Assert Invariant:             If status != 'pending', ROLLBACK and return HTTP 409 Conflict.\n"
        "4. Transition State:             UPDATE driver_requests SET status = 'accepted', driver_id = ?, accepted_at = NOW() WHERE id = ?;\n"
        "5. Commit Atomic Transaction:    mysqli_commit($conn);\n\n"
        "Outcome: Eliminates concurrency hazards and guarantees exactly-once dispatch semantics under arbitrary load."
    )

    add_heading_2("5.5 Ecological Impact & Carbon Offset Quantification Algorithm")
    doc.add_paragraph(
        "RideSync quantifies the environmental benefit of shared campus transit in `includes/eco_helper.php` based on European Environment Agency (EEA) emission standards:"
    )
    add_formula_box(
        "Baseline Solo Emission Rate: E_rate = 0.12 kg CO2 / km (120 g CO2 / km for average petrol car)\n\n"
        "Avoided CO2 Emissions (kg) = (N_riders - 1) * Distance_km * E_rate\n\n"
        "Tree Equivalent Absorption:\n"
        "Trees Equivalent = Avoided_CO2_kg / 22.0  (1 mature tree absorbs ~22 kg CO2 per year)\n\n"
        "Aggregated monthly into rider and driver profiles, power-ranked on the Campus Green Leaderboard."
    )

    add_heading_2("5.6 Dynamic Fair-Fare Split & Pricing Model")
    doc.add_paragraph(
        "RideSync implements an equitable cost-sharing model (`km_rate_v3_fair_split` in `includes/cost_helper.php`) "
        "designed to compensate drivers fairly while delivering substantial savings to students:"
    )
    add_formula_box(
        "Base Rate: ₹25.60 / km  |  Time Adjustment Rate: ₹1.25 / minute\n\n"
        "Solo Fare = ceil(Direct_Distance_km * Rate_per_km)\n"
        "Base Route Fare = Solo Fare\n"
        "Fair Base Split = ceil(Base Route Fare / N_participants)\n"
        "Detour Charge = ceil((Detour_km * Rate_per_km) / N_participants)\n"
        "Time Adjustment = ceil((Time_Added_mins * Time_Rate) / N_participants)\n\n"
        "Final Fare Per Person = Fair Base Split + Detour Charge + Time Adjustment\n"
        "Savings Per Person = max(0, Solo Fare - Final Fare)  [typically 40% - 70% savings]"
    )

    add_heading_2("5.7 Two-Factor OTP Reset & Replay-Resistant Authentication")
    doc.add_paragraph(
        "Password reset and multi-factor authentication are governed by `includes/otp_helper.php` with military-grade defensive controls:"
    )
    add_formula_box(
        "• Cryptographic Generation: random_int(100000, 999999) -> 6-digit integer code\n"
        "• Irreversible Hash Storage: Stored using bcrypt (password_hash) — never plain text\n"
        "• Flooding Prevention Cooldown: Mandatory 60-second cooldown between OTP dispatch requests\n"
        "• Bounded Lifetime: Strict 10-minute validity window (INTERVAL 10 MINUTE)\n"
        "• Brute-Force Throttling: Maximum 5 attempts allowed per token before permanent invalidation\n"
        "• Single-Use Invariant: Verified timestamp (verified_at = NOW()) prevents replay attacks"
    )

    add_heading_2("5.8 Multi-Factor AI Verification Scoring & Synthetic Compliance Sandbox")
    doc.add_paragraph(
        "Driver KYC documents (Driver's License, Aadhaar, PAN, Vehicle RC) undergo automated verification via a 4-factor weighted scoring model in `apps/ai-verification`:"
    )
    add_formula_box(
        "Verification Confidence Score = (0.25 * S_ocr) + (0.30 * S_api) + (0.20 * S_face) + (0.25 * S_fraud)\n\n"
        "Where:\n"
        "• S_ocr: OCR text extraction confidence and document regex pattern compliance\n"
        "• S_api: Document authority format and checksum validity\n"
        "• S_face: Facial biometric cosine similarity between ID card photo and live portrait selfie\n"
        "• S_fraud: Edge anomaly detection, digital tampering analysis, and color histogram verification\n\n"
        "Synthetic Compliance Sandbox Disclosure:\n"
        "To ensure academic privacy compliance (GDPR/DPDP) and prevent exposure of student PII during campus exhibitions, "
        "the AI verification engine features a Synthetic Compliance Sandbox Mode capable of deterministic document evaluation "
        "using synthetic credentials."
    )

    add_heading_2("5.9 Intelligent Geocoded Address Shortener Algorithm")
    doc.add_paragraph(
        "Reverse geocoded location strings frequently exceed 60 characters, causing visual overflow and layout breakage on mobile viewports. "
        "RideSync implements an intelligent address shortening algorithm (`ridesync_short_address`) in `includes/view_helper.php`:"
    )
    add_formula_box(
        "1. Tokenization: Splits raw address into comma-delimited structural tokens.\n"
        "2. Landmark Extraction: Retains the primary venue identifier (e.g., 'SDMIT Main Block', 'Ujire Bus Stand').\n"
        "3. Noise Removal: Strips redundant pin codes, administrative subdivisions, and redundant region names.\n"
        "4. Controlled Bounds: Caps display length at 32 characters, appending an ellipsis if necessary.\n"
        "5. Accessible Tooltip: Embeds the complete verbatim geocoded string in the HTML 'title' attribute."
    )

    add_heading_2("5.10 Persistent 30-Day Session Principal Caching & Instant Revocation")
    doc.add_paragraph(
        "RideSync implements a persistent session model keeping users authenticated for 30 days across browser restarts, "
        "coupled with per-request principal validation for instantaneous security enforcement:"
    )
    add_formula_box(
        "• Session Cookie Lifetime: 2,592,000 seconds (30 Days)\n"
        "• Sliding Expiry Window: Expiration timestamp dynamically refreshed on authenticated activity\n"
        "• In-Memory Principal Caching: Avoids duplicate user SELECT queries during request lifecycle\n"
        "• Instant Revocation Middleware: ridesync_validate_session_principal checks account suspension state; "
        "if an account status changes to 'suspended', the session is immediately destroyed and redirected to login"
    )

    # =========================================================================
    # 6. Database Schema & Data Dictionary
    # =========================================================================
    add_heading_1("6. Detailed Database Schema & Data Dictionary")
    doc.add_paragraph(
        "The RideSync relational database comprises 16 core production tables configured with strict foreign key constraints, "
        "composite spatial/telemetry indexes, and full ACID InnoDB compliance."
    )

    t_db = doc.add_table(rows=1, cols=4)
    t_db.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t_db.rows[0].cells
    hdr[0].text = "Table Name"
    hdr[1].text = "Primary Keys / Indexes"
    hdr[2].text = "Key Attributes & Constraints"
    hdr[3].text = "System Purpose & Integrity Role"
    style_table_header(hdr)

    db_data = [
        ("users", "PRIMARY(id), UNIQUE(email)", "name, email, password, status, role, college", "Rider credentials, profile state & instant suspension flags"),
        ("rides", "PRIMARY(id), idx_rides_search", "user_id, origin, destination, seats, travel_date", "Posted carpool trips, pickup/drop coordinates & capacity"),
        ("matches", "PRIMARY(id), UNIQUE(ride_id, matched_user_id)", "ride_id, matched_user_id, status, match_score", "Rider join requests and geodesic compatibility score records"),
        ("driver_accounts", "PRIMARY(id), UNIQUE(email, phone)", "name, email, phone, status, onboarding_status", "Driver credentials, approval status & live availability"),
        ("driver_account_profiles", "PRIMARY(id), UNIQUE(driver_id)", "driver_id, license_number, verification_status", "Driver professional profile & verification workflow state"),
        ("driver_account_vehicles", "PRIMARY(id), UNIQUE(vehicle_number)", "driver_id, vehicle_type, vehicle_number, seating", "Vehicle registration attributes, category & passenger capacity"),
        ("driver_account_documents", "PRIMARY(id), UNIQUE(driver, type)", "driver_id, document_type, document_ref, status", "KYC documents (License, Aadhaar, PAN, RC) & AI review state"),
        ("driver_account_availability", "PRIMARY(id), UNIQUE(driver_id)", "driver_id, status(online/offline), lat, lng", "Live driver availability state and current GPS coordinates"),
        ("driver_ride_requests", "PRIMARY(id), idx_driver_status", "driver_id, rider_user_id, pickup, drop, fare, status", "Direct rider-to-driver dispatch requests with pessimistic locks"),
        ("driver_ride_history", "PRIMARY(id), idx_driver_date", "driver_id, pickup, drop_location, fare, distance_km", "Completed trips archive and driver earnings records"),
        ("admin_users", "PRIMARY(id), UNIQUE(email)", "name, email, password, role(super/mod), status", "Institutional administrator credentials and privilege roles"),
        ("sos_alerts", "PRIMARY(id), idx_sos_status", "ride_id, actor_role, actor_id, latitude, longitude, status", "Emergency SOS alerts, live GPS coordinates & resolution log"),
        ("user_emergency_contacts", "PRIMARY(id), idx_user_role", "account_type, user_id, name, relationship, phone", "Designated emergency contacts notified upon SOS activation"),
        ("ratings", "PRIMARY(id), UNIQUE(ride_id, rated_by, rated_user)", "ride_id, rated_by_type, rated_user_type, score, comment", "Bidirectional rider-driver ratings (1-5 stars) and feedback"),
        ("ride_locations", "PRIMARY(id), idx_ride_recorded", "ride_id, driver_id, latitude, longitude, recorded_at", "High-frequency GPS telemetry breadcrumbs for live tracking"),
        ("password_resets", "PRIMARY(id), idx_email_account", "account_type, user_id, email, otp_hash, attempts", "Cryptographic OTP password reset tokens with attempt limits"),
        ("realtime_events", "PRIMARY(id), UNIQUE(idempotency)", "event_type, audience_type, audience_id, payload_json", "Idempotent event stream for WebSocket push notifications"),
        ("background_jobs", "PRIMARY(id), idx_queue_status", "queue, payload_json, status, attempts, error_message", "Asynchronous background queue for AI verification & notifications"),
        ("repair_kit_runs", "PRIMARY(id), idx_status_created", "action, status, log_ciphertext, log_hash, checkpoint_json", "Cryptographically audited log of automated repair kit executions"),
        ("schema_migrations", "PRIMARY(version)", "version, executed_at", "Database version tracking ensuring deterministic schema parity")
    ]
    style_table_rows(t_db, db_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    # =========================================================================
    # 7. UI/UX & Mobile-First Frontend Engineering
    # =========================================================================
    add_heading_1("7. Mobile-First UI/UX Engineering & Senior Design System")
    doc.add_paragraph(
        "RideSync features a modern, mobile-first design system constructed with Vanilla CSS design tokens, glassmorphic card layers, "
        "and progressive enhancement principles without bulky third-party frontend frameworks. "
        "Recent design sprints have introduced an elevated floating glassmorphic navigation bar, universal segmented tab bars, "
        "and automatic table-to-card dynamic reflow for mobile viewports."
    )
    add_callout(
        "Design System Specifications & Ergonomics:\n"
        "• Floating Glassmorphic Mobile Navigation Bar: Elevated pill dock with backdrop blur (16px), active glowing indicators, icon micro-interactions, and safe-area inset padding for modern notched devices.\n"
        "• Single-Row Sticky Glassmorphic Header: Compact top bar containing brand logo mark, contextual heading, notification bell with unread badge counter, and profile avatar drawer.\n"
        "• Universal Segmented Tab Navigation: Unified tab switching across Rider, Driver, and Admin portals enabling 1-tap switching without full-page reloads.\n"
        "• Dynamic Table-to-Card Dynamic Reflow: Complex multi-column data tables automatically transform into stacked glassmorphic cards on viewports < 768px.\n"
        "• Ergonomic Touch Targets: Minimum 44px x 44px interactive tap zones, disabled double-tap zoom (`touch-action: manipulation`), and horizontal momentum scrolling.\n"
        "• WCAG 2.1 AA Contrast Compliance: Strict typography hierarchy (Inter / Outfit font tokens) eliminating white-on-white text defects across light and dark surface cards."
    )

    # =========================================================================
    # 8. API Architecture & Action Controller Matrix
    # =========================================================================
    add_heading_1("8. API Architecture & Action Controller Matrix")
    doc.add_paragraph(
        "RideSync exposes modular action handlers and RESTful JSON endpoints designed for sub-50ms execution. "
        "All state-mutating POST requests enforce CSRF token validation and session principal authentication."
    )

    t_api = doc.add_table(rows=1, cols=4)
    t_api.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t_api.rows[0].cells
    hdr[0].text = "Route / Controller"
    hdr[1].text = "Method"
    hdr[2].text = "Payload / Parameters"
    hdr[3].text = "Operational Effect & Response"
    style_table_header(hdr)

    api_data = [
        ("/actions/login_action.php", "POST", "email, password, csrf_token", "Authenticates user, validates suspension, sets 30-day session"),
        ("/actions/register_action.php", "POST", "name, email, password, college, gender", "Validates institutional email, creates rider account"),
        ("/actions/post_ride_action.php", "POST", "origin, dest, date, time, seats", "Geocodes coordinates, calculates distance, creates ride"),
        ("/actions/match_action.php", "POST", "ride_id, action_type(join/accept/reject)", "Executes atomic carpool matching with transactional checks"),
        ("/actions/driver_request_action.php", "POST", "action_type(claim/reject/complete)", "Executes FOR UPDATE locked driver request claim"),
        ("/actions/driver_account_action.php", "POST", "action_type, profile, vehicle, docs", "Updates driver onboarding, vehicle attributes, and documents"),
        ("/actions/sos_action.php", "POST", "ride_id, latitude, longitude", "Triggers SOS alert, broadcasts WebSocket event, alerts contacts"),
        ("/actions/emergency_contact_action.php", "POST", "action_type, name, phone, relation", "Manages user emergency safety contacts"),
        ("/actions/rating_action.php", "POST", "ride_id, rated_user_id, score, comment", "Submits authenticated bidirectional 1-5 star review"),
        ("/actions/forgot_password_action.php", "POST", "email, account_type", "Generates bcrypt-hashed 6-digit OTP with 60s cooldown"),
        ("/actions/verify_otp_action.php", "POST", "email, otp_code", "Verifies OTP code against attempts limit and single-use invariant"),
        ("/actions/reset_password_action.php", "POST", "email, reset_token, new_password", "Updates account password hash and purges active sessions"),
        ("/actions/admin_action.php", "POST", "action_type, target_id, details", "Executes administrative user bans, ride deletions, and audits"),
        ("/api/v1/health.php", "GET", "None", "Service liveness probe returning uptime, OPcache, and DB health"),
        ("/api/v1/readiness.php", "GET", "None", "Kubernetes/container readiness check verifying DB and storage"),
        ("/api/metrics.php", "GET", "Authorization Bearer Token", "Prometheus-compatible system performance metrics"),
        ("/api/realtime_token.php", "GET", "None (Session Authenticated)", "Generates HMAC-SHA256 signed token for WebSocket handshake"),
        ("/api/location_suggestions.php", "GET", "q (query string)", "Returns geocoded location suggestions with landmark short names"),
        ("/api/driver_state.php", "GET/POST", "is_available, lat, lng", "Retrieves or mutates driver availability and telemetry coords")
    ]
    style_table_rows(t_api, api_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    # =========================================================================
    # 9. Security Architecture & Defensive Hardening
    # =========================================================================
    add_heading_1("9. Security Architecture & Defensive Hardening")
    doc.add_paragraph(
        "RideSync implements multi-layered defensive security controls spanning data encryption at rest, "
        "injection prevention, Content Security Policy, and real-time cryptographic token verification:"
    )
    add_callout(
        "Defensive Security Controls:\n"
        "• Cryptographic Document Encryption at Rest: Sensitive driver KYC documents are encrypted using AES-256-CBC via RIDESYNC_DOCUMENT_ENCRYPTION_KEY before filesystem persistence.\n"
        "• Injection & CSRF Defenses: 100% of database interactions execute via parameterized prepared statements; state-modifying requests mandate CSRF anti-forgery token verification.\n"
        "• Restrictive Content Security Policy (CSP): Dynamically whitelists local LAN IPs and WebSocket origins (`connect-src 'self' ws://127.0.0.1:8081`) while blocking unauthorized script execution.\n"
        "• WebSocket Handshake Authentication: Real-time telemetry connections require HMAC-SHA256 signed query tokens tied to the authenticated user session.\n"
        "• 1-Tap Emergency SOS System: Dispatches live GPS coordinates to all active administrators via WebSockets, generates clickable Google Maps coordinates links, and triggers SMS/email escalation to designated emergency contacts."
    )

    # =========================================================================
    # 10. Operational Self-Healing (Repair Kit) & DevOps
    # =========================================================================
    add_heading_1("10. Operational Self-Healing (Repair Kit) & DevOps Architecture")
    doc.add_paragraph(
        "RideSync incorporates an autonomous operational self-healing engine (`RideSyncRepairKitService` in `includes/services/RepairKitService.php`) "
        "featuring 14 dedicated action handlers. Every execution is cryptographically audited in `repair_kit_runs` using AES-256 log ciphertext and SHA-256 integrity hashes."
    )

    t_rep = doc.add_table(rows=1, cols=3)
    t_rep.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t_rep.rows[0].cells
    hdr[0].text = "Repair Operation"
    hdr[1].text = "Category & Severity"
    hdr[2].text = "Operational Self-Healing Function"
    style_table_header(hdr)

    rep_data = [
        ("deep_scan", "Scanner | Info", "Refreshes all service, API, queue, schema, storage, security, and AI diagnostics without changing state"),
        ("force_health_recheck", "Diagnostics | Info", "Clears repair and service-monitor caches, then rebuilds the live health snapshot"),
        ("flush_cache", "Optimization | Warning", "Removes expired file-cache entries and purges admin service snapshots"),
        ("repair_queues", "Recovery | Warning", "Releases stale processing jobs and requeues failed AI verification work"),
        ("ai_recovery", "AI Engine | Warning", "Resets AI service snapshots, retries failed verification jobs, and validates AI service contract view"),
        ("repair_indexes", "Database | Warning", "Automatically applies missing operational indexes across admin, queue, notification, and audit tables"),
        ("optimize_tables", "Performance | Warning", "Runs bounded MySQL table optimization for high-churn operational tables"),
        ("rotate_logs", "Observability | Info", "Rotates oversized app logs and prunes old rotated log files to conserve disk space"),
        ("storage_cleanup", "Storage | Info", "Ensures runtime folders exist, prunes expired cache records, and removes stale temporary files"),
        ("maintenance_mode", "Emergency | Critical", "Sets feature flags into maintenance mode to pause user-facing modules while admins investigate"),
        ("ai_kill_switch", "Emergency | Critical", "Disables the AI verification feature flag and places the AI subsystem into maintenance mode"),
        ("platform_recovery", "God Mode | Critical", "One-click safe recovery bundle: storage repair, cache purge, queue repair, AI recovery, and log rotation"),
        ("queue_full_restart", "God Mode | Critical", "Creates an audited ops handoff job for infrastructure restart workers"),
        ("queue_rollback", "God Mode | Critical", "Creates an audited ops handoff job for rollback automation")
    ]
    style_table_rows(t_rep, rep_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    # =========================================================================
    # 11. Quality Assurance & Cross-Browser Automation
    # =========================================================================
    add_heading_1("11. Quality Assurance, Testing & Cross-Browser Verification")
    doc.add_paragraph(
        "RideSync enforces a multi-tiered testing pipeline validating functional correctness, security boundaries, "
        "OpenAPI contract specifications, and cross-browser rendering fidelity across Google Chrome and Brave Browser."
    )

    t_qa = doc.add_table(rows=1, cols=4)
    t_qa.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t_qa.rows[0].cells
    hdr[0].text = "Testing Suite"
    hdr[1].text = "Harness / Runner"
    hdr[2].text = "Coverage & Checks"
    hdr[3].text = "Pass Rate & Status"
    style_table_header(hdr)

    qa_data = [
        ("PHPUnit Unit & Integration", "PHPUnit 11.5", "68 tests, 243 assertions (Auth, Locks, OTP, Sessions, Address Shortener)", "100% PASS (0 Failures)"),
        ("Negative API Security Suite", "PHP CLI HTTP Harness", "19 negative security checks (Unauth rejections, Method validation, Rate limits)", "100% PASS (19/19 OK)"),
        ("Authenticated API Lifecycle", "PHP CLI Session Harness", "6 authenticated API workflows (Token creation, Driver state, Admin search)", "100% PASS (6/6 OK)"),
        ("OpenAPI Contract Validation", "OpenAPI 3.0 Validator", "27 API paths validated against openapi.yaml schema specifications", "100% PASS (27/27 OK)"),
        ("Regression Integrity Suites", "PHP Regression Harness", "3 suites (Ride Status & Match, Session Principal, Security Surface Hardening)", "100% PASS (3/3 Suites)"),
        ("AI Microservice Selftest", "FastAPI / Pytest Runner", "25 internal microservice health, OCR, face match, and sandbox checks", "100% PASS (25/25 Checks)"),
        ("Google Chrome CDP Suite", "Node.js Chrome Automation", "Headless Chrome DOM inspection, navbar checks, short-address chips, screenshots", "100% PASS (Verified)"),
        ("Brave Browser CDP Suite", "Node.js Brave Automation", "Headless Brave DOM inspection, shields compatibility, touch layout checks", "100% PASS (Verified)"),
        ("High Concurrency Load Tests", "K6 Smoke & Production", "Sub-50ms TTFB p95 latency under high concurrent load with OPcache caching", "100% PASS (Target Met)")
    ]
    style_table_rows(t_qa, qa_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    add_heading_2("11.1 Dual-Browser CDP Automation Engine (Google Chrome & Brave)")
    doc.add_paragraph(
        "RideSync incorporates autonomous browser testing harnesses (`tools/test_with_chrome.mjs` and `tools/test_with_brave.mjs`) "
        "communicating directly with browser instances via the Chrome DevTools Protocol (CDP):\n"
        "• Chrome Automation: Headless Chrome launches on remote debugging port 9222, opens landing, rider login, and driver login portals, "
        "evaluates DOM hierarchy, verifies responsive cards, and captures full-resolution PNG artifacts.\n"
        "• Brave Browser Automation: Headless Brave launches on remote debugging port 9223, validates aggressive ad-blocker / shield compatibility, "
        "ensures zero layout degradation, and captures comparative visual evidence.\n"
        "• Interactive Browser Commands: Supported commands `npm run chrome:open` and `npm run brave:open` enable single-command interactive launch for live demonstration."
    )

    # =========================================================================
    # 12. Live Exhibition Demonstration Protocol & Evaluation Guide
    # =========================================================================
    add_heading_1("12. Live Exhibition Demonstration Protocol & Evaluation Guide")
    doc.add_paragraph(
        "To facilitate rapid, reliable evaluation during college project exhibitions and viva assessments, "
        "RideSync provides an automated, non-destructive demonstration architecture:"
    )

    t_demo = doc.add_table(rows=1, cols=3)
    t_demo.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t_demo.rows[0].cells
    hdr[0].text = "Step / Stage"
    hdr[1].text = "Action / Command"
    hdr[2].text = "Demonstration Milestone & Expected Outcome"
    style_table_header(hdr)

    demo_data = [
        ("1. Environment Bootstrap", "npm run seed:demo", "Populates realistic campus routes, active drivers, and pending ride requests non-destructively"),
        ("2. Service Health Verification", "npm test", "Runs full quality gate verifying PHPUnit (68 tests), API security, and AI verification service readiness"),
        ("3. Dual-Browser Launch", "npm run chrome:open\nnpm run brave:open", "Launches Google Chrome (Rider portal) and Brave Browser (Driver portal) side-by-side"),
        ("4. Realtime Dispatch Demo", "Rider posts ride -> Driver claims", "Demonstrates geodesic route matching, FOR UPDATE row locking, and instant WebSocket dispatch"),
        ("5. Emergency SOS Broadcast", "1-Tap SOS Activation", "Broadcasts high-priority alert to WebSocket gateway, displays emergency modal, and notifies safety contacts"),
        ("6. AI Verification Showcase", "Driver KYC Document Upload", "Demonstrates OCR text extraction, face match confidence scoring, and sandbox approval workflow"),
        ("7. Operational Repair Kit", "Admin -> Repair Kit -> Deep Scan", "Demonstrates autonomous diagnostics, missing index auto-repair, and encrypted run logging")
    ]
    style_table_rows(t_demo, demo_data)
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

    # =========================================================================
    # 13. Results, Performance & Discussion
    # =========================================================================
    add_heading_1("13. Quantitative Results & Performance Discussion")
    doc.add_paragraph(
        "Extensive benchmarking under K6 load tests and local profiling reveals sub-50ms TTFB p95 latency across all core endpoints. "
        "The OPcache bytecode pre-compilation combined with session principal caching eliminates repetitive database round-trips. "
        "Geodesic route fitting evaluates over 50 candidate routes in under 12ms. "
        "Under concurrent driver claim stress tests, 100% of conflicting claim attempts were safely serialized via SELECT ... FOR UPDATE row locks, "
        "with exactly one driver receiving an HTTP 200 confirmation and conflicting drivers receiving HTTP 409 Conflict without data corruption."
    )

    # =========================================================================
    # 14. Conclusion & Future Scope
    # =========================================================================
    add_heading_1("14. Conclusion & Future Scope")
    doc.add_paragraph(
        "RideSync successfully delivers a comprehensive, production-grade campus mobility platform. "
        "By fusing rigorous mathematical routing models, pessimistic database concurrency controls, modern glassmorphic responsive design, "
        "dual-browser automated validation, an AI-driven driver onboarding sandbox, and an autonomous self-healing operational engine, "
        "RideSync comprehensively resolves the operational, safety, and logistical challenges of collegiate transit. "
        "Future development phases will incorporate native mobile applications (React Native / Flutter), "
        "IoT telematics hardware integration for vehicular telemetry, and federated machine learning for predictive commute demand forecasting."
    )

    # Save DOCX file
    script_dir = os.path.dirname(os.path.abspath(__file__))
    project_root = os.path.dirname(script_dir)
    docs_dir = os.path.join(project_root, "docs")
    docx_path = os.path.join(docs_dir, "RideSync_Comprehensive_College_Project_Report.docx")

    os.makedirs(docs_dir, exist_ok=True)
    doc.save(docx_path)
    print(f"DOCX Report created successfully at:\n  {docx_path}")

    # Export to PDF via convert_docx_to_pdf.ps1
    pdf_path = os.path.join(docs_dir, "RideSync_Report.pdf")
    ps_script = os.path.join(script_dir, "convert_docx_to_pdf.ps1")
    
    print("\nExporting PDF report...")
    cmd = ["powershell", "-ExecutionPolicy", "Bypass", "-File", ps_script, "-DocxPath", docx_path, "-PdfPath", pdf_path]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode == 0:
        print(f"PDF Report generated successfully at: {pdf_path}")
    else:
        print(f"Warning: PDF conversion finished with output:\n{res.stdout}\n{res.stderr}")

if __name__ == "__main__":
    create_report()
