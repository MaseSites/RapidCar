# AutoScout24: Anfrage als Integrationspartner

## Der Ablauf, den wir wollen

1. Ein Kunde registriert sich bei uns mit eigenem Konto
2. Er verwaltet dort ausschliesslich seine eigenen Fahrzeuge
3. Er nennt uns nur seine AutoScout24-Kundennummer
4. **Wir** kümmern uns um den Schnittstellen-Zugang, nicht der Kunde
5. Danach gehen seine Inserate automatisch hinaus

Der Kunde muss also weder selbst einen Zugang beantragen noch uns sein
Passwort geben.

## Was die Schnittstelle dazu hergibt

Geprüft an der offiziellen Beschreibung
(`https://listing-creation.api.autoscout24.com/assets/openapi/spec.yml`):

- **Anmeldung:** HTTP Basic Auth. Kein OAuth, keine Anmeldung über Google
  oder Facebook. Ein Konto, das nur mit Google angelegt wurde, kann die
  Schnittstelle deshalb nicht nutzen.
- **`GET /customers`** liefert *"all customers the user can operate on behalf
  of"*. Genau das ist das Partnermodell: ein Zugang, viele Kunden.
- **Beide Verkäufertypen** sind vorgesehen: die Beschreibung unterscheidet
  ausdrücklich `sellerType='Dealer'` und `sellerType='Private'`.
- **Kein Endpunkt legt Kunden an.** Es gibt kein `POST /customers`. Die
  Zuordnung eines Kunden zu unserem Zugang läuft also nicht über die
  Schnittstelle, sondern über AutoScout24 selbst. Das ist der Punkt, den
  die Anfrage klären muss.

---

## E-Mail

Von der Adresse senden, die zum AutoScout24-Konto gehört.
Empfänger: `daten@autoscout24.de` (steht als Kontakt in der Beschreibung),
zusätzlich der eigene Kundenberater, falls vorhanden.

> **Betreff:** Listing Creation API: Zugang als Integrationspartner für mehrere Kunden
>
> Guten Tag
>
> Wir betreiben RapidCar, eine Schweizer Software, mit der Autohäuser und
> private Verkäufer aus Fahrzeugfotos ein vollständiges Inserat erzeugen und
> es anschliessend auf AutoScout24 veröffentlichen.
>
> Wir möchten die Listing Creation API als Integrationspartner nutzen: Jeder
> Kunde registriert sich bei uns mit eigenem Konto und verwaltet dort nur
> seine eigenen Fahrzeuge. Den Schnittstellen-Zugang möchten wir zentral
> übernehmen, damit unsere Kunden weder selbst einen Zugang beantragen noch
> uns ihr Passwort geben müssen. Die Beschreibung sieht das mit
> `GET /customers` ("all customers the user can operate on behalf of")
> ausdrücklich vor.
>
> Unsere Fragen:
>
> 1. Welche Voraussetzungen müssen wir als Partner erfüllen, um einen
>    solchen Zugang zu erhalten?
> 2. Wie ordnen wir einen einzelnen Kunden unserem Zugang zu, sodass seine
>    Kundennummer in `GET /customers` erscheint? Genügt dafür eine
>    Einverständniserklärung des Kunden, und in welcher Form?
> 3. Gilt das auch für private Verkäufer (`sellerType='Private'`), oder ist
>    der Zugang auf Händlerkonten beschränkt?
> 4. Gibt es eine Testumgebung, in der wir die Anbindung prüfen können,
>    bevor echte Inserate entstehen?
>
> Unsere technische Umsetzung steht bereits: Fahrzeugdaten, Bilder,
> Ausstattung und Veröffentlichungsstatus werden vollständig nach Ihrer
> Beschreibung übertragen.
>
> Freundliche Grüsse
> [Name, Firma, Adresse, Telefon, AutoScout24-Kundennummer]

---

## Worauf es in der Antwort ankommt

- **Bekommen wir einen Partner-Zugang?** Ohne ihn bleibt nur der Weg, dass
  jeder Kunde eigene Zugangsdaten bei uns hinterlegt. Beides ist in der
  Anwendung eingebaut.
- **Wie wird ein Kunde zugeordnet?** Danach richtet sich, was wir im
  Formular "Verbindung anfordern" vom Kunden abfragen müssen.
- **Private Verkäufer erlaubt?** Falls nein, gilt die Anbindung nur für
  Autohäuser, und private Konten brauchen einen anderen Weg.
- **Testzugang?** Ohne ihn testen wir gegen echte Inserate, die wir sofort
  wieder deaktivieren müssten.

---

## Stand in der Anwendung

Beide Wege sind gebaut und laufen ohne weitere Arbeit:

| Fall | Was der Kunde sieht |
|---|---|
| Kein Partner-Zugang hinterlegt | Anmeldung mit eigenen AutoScout24-Daten, dann Kundennummer wählen |
| Partner-Zugang da, Kunde zugeordnet | Nur noch Kundennummer wählen, kein Passwort |
| Partner-Zugang da, Kunde noch nicht zugeordnet | Formular "Verbindung anfordern": er nennt seine Kundennummer, wir bekommen eine Nachricht |

Der Partner-Zugang wird in der Verwaltung unter Einstellungen eingetragen.
Er wird vor dem Speichern bei AutoScout24 geprüft und verschlüsselt in der
Datenbank abgelegt.
