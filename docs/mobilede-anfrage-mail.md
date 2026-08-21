# Die Mail an mobile.de

**Empfänger:** service@team.mobile.de
**Absender:** die E-Mail-Adresse, die zum eigenen mobile.de-Konto gehört.

Vor dem Senden ausfüllen: die eckigen Klammern am Ende.

---

**Betreff:** Seller-API: Zugang als Transfer Service Provider und Sandbox

Guten Tag

Wir entwickeln derzeit RapidCar, eine Schweizer Software für
Fahrzeuginserate. Autohäuser und private Verkäufer laden bei uns Fotos eines
Fahrzeugs hoch, unsere Software erstellt daraus ein vollständiges Inserat mit
Fahrzeugdaten, Titel, Beschreibung und Ausstattung, und veröffentlicht es
anschliessend auf den Verkaufsplattformen.

Wir befinden uns noch in der Entwicklung. Unsere Seite rapid-car.com ist
zurzeit eine vorläufige Fassung und noch nicht öffentlich freigegeben; wir
bitten das zu berücksichtigen, falls Sie einen Blick darauf werfen. Wir
klären den Zugang bewusst jetzt, damit wir die Anbindung von Anfang an
richtig bauen, statt nachträglich umzustellen.

Wir möchten die Seller-API als Transfer Service Provider nutzen, so wie Ihre
Dokumentation es beschreibt: ein API-Konto, das im Namen mehrerer verknüpfter
Verkäufer inseriert. Unsere Kunden sollen dafür weder selbst einen Zugang
beantragen noch uns ihr mobile.de-Passwort geben müssen.

Dazu unsere Fragen.

**1. Zugang als Transfer Service Provider**

- Welche Voraussetzungen müssen wir erfüllen, um ein API-Konto als TSP zu
  erhalten?
- Ist dafür ein Vertrag nötig, und entstehen laufende Kosten?
- Wir gehen davon aus, dass wir Benutzername und Passwort für HTTP Basic Auth
  erhalten. Ist das korrekt?

**2. Verknüpfung eines Verkäufers mit unserem Konto**

Das ist für uns der wichtigste Punkt. `GET /seller-api/sellers` liefert die
verknüpften Verkäufer, aber die Verknüpfung selbst lässt sich über die
Schnittstelle nicht herstellen.

- Wie wird ein einzelner Händler mit unserem TSP-Konto verknüpft, sodass er
  in `GET /seller-api/sellers` erscheint?
- Genügt dafür eine schriftliche Einverständniserklärung des Händlers? Falls
  ja, welche Angaben muss sie enthalten und wohin senden wir sie?
- Muss der Händler in seinem Kundenbereich selbst etwas aktivieren, etwa die
  Inseratsbindung?
- Wie lange dauert eine solche Verknüpfung erfahrungsgemäss?
- Können wir sie wieder lösen, wenn ein Händler uns verlässt?

**3. Nur eine Anbindung je Händler?**

Uns wurde berichtet, dass pro Konto nur eine Seller-API-Anbindung
gleichzeitig aktiv sein kann.

- Stimmt das?
- Falls ja: Gilt diese Einschränkung auch, wenn wir als TSP für den Händler
  inserieren? Also müsste ein Händler seine bestehende Anbindung
  (zum Beispiel sein Händlerverwaltungssystem) abschalten, um zusätzlich
  über uns zu inserieren?
- Gibt es einen Weg, beides parallel zu betreiben?

**4. Private Verkäufer**

- Gilt das TSP-Modell auch für private Verkäufer, oder ausschliesslich für
  Händlerkonten?

**5. Sandbox**

Wir haben `https://services.sandbox.mobile.de` in Ihrer Dokumentation
gefunden.

- Bitte um Zugangsdaten für einen API-User in der Sandbox, damit wir die
  Anbindung prüfen können, ohne dass echte Anzeigen entstehen.
- Sind die Sandbox-Zugangsdaten unabhängig vom späteren Produktivzugang?

**6. Grenzen im Betrieb**

- Welche Anfragegrenzen gelten pro Minute und pro Tag?
- Wie viele Bilder sind je Anzeige zulässig, und welche maximale Dateigrösse?
- Werden hochgeladene Bilder automatisch entfernt, wenn eine Anzeige gelöscht
  wird?

**7. Preise in Franken**

Wir sind ein Schweizer Anbieter. Ihre Beispiele zeigen ausschliesslich EUR.

- Akzeptiert `price.currency` auch CHF, oder müssen Schweizer Händler ihre
  Preise in Euro angeben?

Aktuell blockieren wir die Übertragung, wenn im Konto eine andere Währung als
Euro eingestellt ist, statt einen Frankenbetrag als Euro auszugeben. Wir
möchten wissen, ob das nötig bleibt.

**Zum Stand unserer Umsetzung**

Die technische Anbindung ist programmiert und richtet sich nach Ihrer
OpenAPI-Beschreibung: Fahrzeugdaten, Kategorien und Ausstattung aus Ihren
Referenzlisten unter `services.mobile.de/refdata`, Bilder über
`POST /seller-api/images`, Anzeigen über
`POST /seller-api/sellers/{sellerId}/ads`. Getestet haben wir bisher nur
gegen die Beschreibung selbst, da uns noch kein Zugang vorliegt. Echte
Anzeigen haben wir zu keinem Zeitpunkt erzeugt.

Für Rückfragen stehen wir jederzeit zur Verfügung, gerne auch telefonisch.

Freundliche Grüsse

[Vor- und Nachname]
[Firma, Rechtsform]
[Strasse, PLZ Ort]
[Telefon]
[E-Mail]
[eigene mobile.de-Kundennummer, falls vorhanden]
[Website: rapid-car.com]

---

## Beilage: Einverständniserklärung des Händlers

Falls mobile.de eine Vollmacht verlangt.

> **Einverständniserklärung zur Nutzung der mobile.de Seller-API**
>
> Hiermit bestätige ich, dass die Firma [deine Firma] berechtigt ist, über die
> Seller-API von mobile.de Fahrzeuganzeigen in meinem Namen zu erstellen, zu
> ändern, zu veröffentlichen und zu entfernen.
>
> mobile.de-Kundennummer: ................................
> Firma / Name: ................................
> Adresse: ................................
>
> Diese Erklärung gilt bis auf Widerruf. Ein Widerruf ist jederzeit formlos
> gegenüber mobile.de oder [deine Firma] möglich.
>
> Ort, Datum: ................................
> Unterschrift: ................................

---

## Stand in der Anwendung

Beide Wege sind gebaut und laufen ohne weitere Arbeit:

| Fall | Was der Kunde sieht |
|---|---|
| Kein Betreiber-Zugang hinterlegt | Anmeldung mit eigenen mobile.de-Daten, dann Verkäuferkonto wählen |
| Betreiber-Zugang da, Kunde verknüpft | Nur noch Verkäuferkonto wählen, kein Passwort |
| Betreiber-Zugang da, Kunde noch nicht verknüpft | Formular "Verbindung anfordern": er nennt seine Kundennummer, wir bekommen eine Nachricht |

Der Betreiber-Zugang wird in der Verwaltung unter Einstellungen eingetragen.
Er wird vor dem Speichern bei mobile.de geprüft und verschlüsselt in der
Datenbank abgelegt. Die Sandbox lässt sich mit
`channels.mobile_de.sandbox => true` einschalten.
