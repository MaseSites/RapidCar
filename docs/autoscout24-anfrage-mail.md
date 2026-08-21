# Die Mail an AutoScout24

**Empfänger:** daten@autoscout24.de
**Kopie an:** den eigenen Kundenberater, falls vorhanden
**Absender:** die E-Mail-Adresse, die zum eigenen AutoScout24-Konto gehört.
Das ist Bedingung, sonst wird die Anfrage nicht bearbeitet.

Vor dem Senden ausfüllen: die eckigen Klammern am Ende.

---

**Betreff:** Listing Creation API: Partnerzugang für die Veröffentlichung im Namen unserer Kunden

Guten Tag

Wir betreiben RapidCar (rapid-car.com), eine Schweizer Software für
Fahrzeuginserate. Autohäuser und private Verkäufer laden bei uns Fotos eines
Fahrzeugs hoch, unsere Software erstellt daraus ein vollständiges Inserat mit
Fahrzeugdaten, Titel, Beschreibung und Ausstattung, und veröffentlicht es
anschliessend auf AutoScout24.

Wir möchten die Listing Creation API als Integrationspartner nutzen. Unser
Ziel: Jeder Kunde registriert sich bei uns mit seinem eigenen Konto, verwaltet
dort ausschliesslich seine eigenen Fahrzeuge, und wir veröffentlichen in
seinem Namen. Der Kunde soll dafür weder selbst einen Schnittstellen-Zugang
beantragen noch uns sein AutoScout24-Passwort geben müssen. Ihre Beschreibung
sieht dieses Modell mit `GET /customers` ("all customers the user can operate
on behalf of") ausdrücklich vor.

Damit wir das vollständig und regelkonform umsetzen können, bitten wir um
Antwort auf die folgenden Punkte.

**1. Partnerzugang**

- Welche Voraussetzungen müssen wir erfüllen, um einen Zugang zur Listing
  Creation API zu erhalten, der im Namen mehrerer Kunden arbeiten darf?
- Ist dafür ein Partnervertrag nötig, und entstehen laufende Kosten?
- Wir gehen davon aus, dass wir Benutzername und Passwort für HTTP Basic Auth
  erhalten, da die Schnittstelle `basicAuth` als einziges Verfahren nennt. Ist
  das korrekt?

**2. Zuordnung eines Kunden zu unserem Zugang**

Das ist für uns der wichtigste Punkt, weil es dafür keinen Endpunkt gibt.

- Wie wird ein einzelner Kunde unserem Zugang zugeordnet, sodass seine
  Kundennummer in `GET /customers` erscheint?
- Genügt dafür eine schriftliche Einverständniserklärung des Kunden? Falls ja,
  welche Angaben muss sie enthalten und an welche Adresse senden wir sie?
- Gibt es dafür ein Formular oder einen festen Ablauf, den wir unseren Kunden
  vorlegen können?
- Wie lange dauert eine solche Zuordnung erfahrungsgemäss?
- Können wir eine Zuordnung auch wieder aufheben, wenn ein Kunde uns verlässt?

**3. Private Verkäufer**

Ihre Beschreibung unterscheidet `sellerType='Dealer'` und
`sellerType='Private'`.

- Gilt der Partnerzugang auch für private Verkäufer, oder ausschliesslich für
  Händlerkonten?
- Falls private Verkäufer möglich sind: Welche Voraussetzungen gelten dort,
  und unterscheidet sich die Zuordnung von der eines Händlers?

**4. Konten mit Anmeldung über Google oder Facebook**

Da die Schnittstelle Basic Auth verwendet, gehen wir davon aus, dass ein
Konto, das ausschliesslich über einen externen Anmeldedienst angelegt wurde,
die Schnittstelle nicht nutzen kann.

- Ist das richtig?
- Falls ja: Wie kann ein solcher Kunde ein Passwort für die Schnittstelle
  erhalten, ohne sein bestehendes Konto zu verlieren?

**5. Grenzen im Betrieb**

- Welche Anfragegrenzen gelten (die Beschreibung nennt HTTP 429, aber keine
  Zahlen)? Wie viele Anfragen pro Minute und pro Tag sind zulässig?
- Gibt es eine Höchstzahl an Bildern je Inserat und eine maximale Dateigrösse?
  Wir senden derzeit bis zu 30 Bilder je Fahrzeug.
- Werden hochgeladene Bilder automatisch entfernt, wenn ein Inserat gelöscht
  wird, oder müssen wir sie separat aufräumen?

**6. Testbetrieb**

Wir haben den Header `X-Testmode` in Ihrer Beschreibung gefunden.

- Ist das der vorgesehene Weg, um die Anbindung zu prüfen, ohne dass echte
  Inserate erscheinen?
- Können wir ihn bereits vor der endgültigen Freischaltung nutzen?

**Zum Stand unserer Umsetzung**

Die technische Anbindung ist fertig und richtet sich vollständig nach Ihrer
Beschreibung: Fahrzeugdaten, Marken und Modelle über die Referenz-API
aufgelöst, Ausstattung über die Equipment-Referenzen, Bilder über
`POST /customers/{customerId}/images`, Veröffentlichung und Statuswechsel über
`publication`. Wir warten allein auf den Zugang.

Für Rückfragen stehen wir jederzeit zur Verfügung, gerne auch telefonisch.

Freundliche Grüsse

[Vor- und Nachname]
[Firma, Rechtsform]
[Strasse, PLZ Ort]
[Telefon]
[E-Mail]
[eigene AutoScout24-Kundennummer, falls vorhanden]
[Website: rapid-car.com]

---

## Beilage: Einverständniserklärung des Kunden

Falls AutoScout24 eine Vollmacht verlangt, kann dieser Text verwendet werden.
Der Kunde druckt ihn aus, unterschreibt und schickt ihn zurück.

> **Einverständniserklärung zur Nutzung der AutoScout24-Schnittstelle**
>
> Hiermit bestätige ich, dass die Firma [deine Firma] berechtigt ist, über die
> Listing Creation API von AutoScout24 Fahrzeuginserate in meinem Namen zu
> erstellen, zu ändern, zu veröffentlichen und zu entfernen.
>
> AutoScout24-Kundennummer: ................................
> Firma / Name: ................................
> Adresse: ................................
>
> Diese Erklärung gilt bis auf Widerruf. Ein Widerruf ist jederzeit formlos
> gegenüber AutoScout24 oder [deine Firma] möglich.
>
> Ort, Datum: ................................
> Unterschrift: ................................
