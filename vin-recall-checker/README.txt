=== VIN Recall Checker ===
Requires at least: 5.8
Requires PHP: 7.4 (με την επέκταση OpenSSL)
Stable tag: 1.0.0

Έλεγχος μέσω αριθμού πλαισίου (VIN) για ανοιχτές καμπάνιες ανάκλησης / τεχνικής
αναβάθμισης, απευθείας από το σύστημα του κατασκευαστή (IAS → iDMS,
"getUnfinishedTechnologyActivityByVin").

== Εγκατάσταση ==

1. Ανεβάστε τον φάκελο vin-recall-checker στο wp-content/plugins/ (ή το zip από
   Πρόσθετα → Προσθήκη νέου → Μεταφόρτωση) και ενεργοποιήστε το.
2. Ρυθμίσεις → Ανακλήσεις VIN: συμπληρώστε
   - Auth / Token endpoint  (π.χ. https://…/api/auth/getToken)
   - API base URL           (π.χ. https://…/api/externalIntegration)
   - App ID, App Secret
   Αποθηκεύστε και πατήστε «Δοκιμή λήψης token», μετά «Δοκιμή VIN».
3. Βάλτε το shortcode [vin_recall_check] στη σελίδα που θέλετε
   (π.χ. «Ανακλήσεις» ή «Service»). Προαιρετικά:
   [vin_recall_check title="Έλεγχος ανάκλησης" intro="…"]

== Πώς δουλεύει ==

- Ο επισκέπτης γράφει το VIN. Ο browser καλεί το WordPress
  (/wp-json/vrc/v1/check) και ο server του site καλεί τον κατασκευαστή.
  Τα στοιχεία σύνδεσης και το token δεν φτάνουν ποτέ στον browser.
- Token: GET στο Token endpoint με app-id και app-secret. Το secret
  κρυπτογραφείται με AES/CBC/PKCS5Padding (κλειδί = IV = "CheryOdin!202503")
  και στέλνεται σε hex, όπως στην ενότητα 2.1.4 της τεκμηρίωσης. Το token
  ισχύει 5 ώρες και κρατιέται 4,5 ώρες. Αν απορριφθεί νωρίτερα, ανανεώνεται
  αυτόματα.
- Ερώτημα: POST {"vin": "…"} με headers APP-ID και APP-TOKEN.
  vinExist = false               → «Το VIN δεν βρέθηκε»
  unfinishedCount > 0            → «Υπάρχουν N ανοιχτές καμπάνιες»
                                   (+ χρόνος εργασίας, κουμπί ραντεβού / τηλέφωνο)
  unfinishedCount = 0            → «Δεν υπάρχει ανοιχτή καμπάνια»
- Σε σφάλμα δικτύου, HTTP 429 ή 5xx γίνονται έως 2 επαναλήψεις (μετά από 1 δευτ.
  και 2 δευτ.).
- Οι απαντήσεις κρατιούνται 60 λεπτά ανά VIN (ρυθμίζεται). Ισχύει όριο 20
  ελέγχων ανά IP την ώρα. Υπάρχει και κρυφό πεδίο-παγίδα (honeypot) για bots.

== Ασφάλεια: στοιχεία στο wp-config.php (προτείνεται) ==

Αντί για τη βάση δεδομένων, τα στοιχεία μπορούν να οριστούν στο wp-config.php.
Τότε υπερισχύουν και εμφανίζονται κλειδωμένα στις ρυθμίσεις:

    define('VRC_TOKEN_URL',    'https://…/api/auth/getToken');
    define('VRC_API_BASE_URL', 'https://…/api/externalIntegration');
    define('VRC_APP_ID',       '…');
    define('VRC_APP_SECRET',   '…');

== Αντιμετώπιση προβλημάτων ==

- «No matching appId and appSecret»: δοκιμάστε την επιλογή «Αποστολή app-id /
  app-secret» (headers / query string). Αν το secret που λάβατε είναι ήδη
  μεγάλο hex string κρυπτογραφημένο από τον κατασκευαστή, τσεκάρετε «ήδη
  κρυπτογραφημένο».
- 404 στο ερώτημα VIN: ελέγξτε την «Τελική διεύθυνση ερωτήματος» στη σελίδα
  ρυθμίσεων. Πρέπει να περιέχει /api/externalIntegration/v1/eu/dms/…
- Ο κατασκευαστής μπορεί να ζητήσει τη δημόσια IP του server για whitelist.
- Αν ο server βρίσκεται πίσω από proxy/CDN, η IP του επισκέπτη για το όριο
  ελέγχων προσαρμόζεται με το φίλτρο 'vrc_client_ip'.
- Τα σφάλματα σύνδεσης καταγράφονται στο κάτω μέρος της σελίδας ρυθμίσεων.

== Εμφάνιση ==

Τα χρώματα αλλάζουν από το θέμα σας μέσω CSS μεταβλητών, π.χ.:

    .vrc-checker { --vrc-accent: #00a0b0; --vrc-radius: 0; }
