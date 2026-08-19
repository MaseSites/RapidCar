# AutoScout24: Anfrage als Integrationspartner

## Der Ablauf, den wir wollen

1. Ein Autohaus registriert sich bei uns mit eigenem Konto
2. Es verwaltet dort ausschliesslich seine eigenen Fahrzeuge
3. Es verbindet sich mit einem Klick mit AutoScout24
4. **Wir** kümmern uns um den Schnittstellen-Zugang, nicht der Händler

Das Autohaus muss also weder selbst einen Zugang beantragen noch uns sein
Passwort geben.

Dass die API das kann, steht in der Dokumentation:
`GET /customers` liefert *"all customers the user can operate on behalf of"*.

---

## E-Mail

Von der Adresse senden, die zum AutoScout24-Konto gehört.
Empfänger: Kundenberater oder Händler-Support.

> **Betreff:** Listing-Creation-API: mehrere Händler über einen Zugang
>
> Guten Tag
>
> Wir entwickeln eine Software für Autohäuser. Jeder Händler registriert sich
> bei uns mit einem eigenen Konto und verwaltet dort nur seine eigenen
> Fahrzeuge, die er auf AutoScout24 inserieren möchte.
>
> Den Schnittstellen-Zugang möchten wir zentral übernehmen, damit unsere
> Händler weder selbst einen Zugang beantragen noch uns ihr Passwort geben
> müssen (gemäss `GET /customers`, "customers the user can operate on behalf
> of").
>
> Unsere Frage: Wie ordnen wir einen Händler möglichst einfach unserem Zugang
> zu, und welche Voraussetzungen gelten dafür für uns?
>
> Freundliche Grüsse
> [Name, Firma, Telefon]

---

## Worauf es in der Antwort ankommt

Die Antwort auf **"wie ordnen wir einen Händler zu"** bestimmt Schritt 3 oben:

| Mögliche Antwort | Folge für das Onboarding |
|---|---|
| Händler gibt bei AutoScout24 eine Freigabe | Ein Klick, danach erscheint seine Kundennummer bei uns |
| Formular oder Meldung pro Händler | Einmalige Meldung durch uns, dann verbunden |
| Nicht möglich | Rückfall: jedes Autohaus hinterlegt eigene Zugangsdaten |

## Was bereits vorbereitet ist

- **Registrierung, eigenes Konto, eigene Fahrzeuge:** funktioniert bereits.
  Jedes Autohaus sieht ausschliesslich seine eigenen Daten.
- **Partnerzugang:** `autoscout.platform_username` und `autoscout.platform_password`
  in `config/config.php` setzen. Der Händler wählt dann nur seine Kundennummer,
  ohne Passwort.
- **Eigener Zugang je Autohaus:** Bleiben die Werte leer, hinterlegt jedes
  Autohaus eigene Zugangsdaten.
- **Trennung:** Eine Kundennummer gehört genau einem Autohaus. Bereits
  vergebene Nummern erscheinen bei anderen nicht zur Auswahl.

Alle drei Varianten laufen ohne Code-Änderung, nur über die Konfiguration.
