# Anfragen an die übrigen Plattformen

Geprüft wurde für jede Plattform, ob es eine offene Schnittstelle gibt.
Ergebnis:

| Plattform | Schnittstelle | Weg |
|---|---|---|
| AutoScout24 | ja, dokumentiert | eingebaut, Partneranfrage läuft |
| mobile.de | ja, dokumentiert (TSP) | eingebaut, Anfrage vorbereitet |
| Facebook Marketplace | nimmt eine Fahrzeugliste als Datei | **Adresse steht bereit, siehe unten** |

| Autolina | keine öffentliche | Mail |
| tutti.ch | keine öffentliche | Mail |
| Ricardo | ja, eigene Schnittstelle | **eingebaut**, Partnerschlüssel anfragen |
| Kleinanzeigen | keine öffentliche | über mobile.de, siehe unten |

---

## Die Adressen

| Plattform | Adresse | Quelle |
|---|---|---|
| Autolina | `service@autolina.ch` | Kontaktseite autolina.ch, selbst nachgesehen |
| Ricardo | `premiumservice@ricardo.ch` | Team für gewerbliche Verkäufer, Telefon 041 769 33 44 |
| tutti.ch | `hilfe@tutti.ch` | Support-Adresse; für Gewerbliches gibt es kein eigenes Postfach |

| Kleinanzeigen | über das Formular für gewerbliche Anbieter | keine offene Adresse veröffentlicht |
| Meta | über den Meta Business Support | keine offene Adresse |

**Entfernt:** car4you gibt es nicht mehr, und Comparis ist keine eigene
Plattform, auf der man inserieren kann. Beide sind aus der Anwendung raus.

---

## Facebook Marketplace: keine Mail nötig

Meta nimmt eine Fahrzeugliste als Datei entgegen. Die Adresse dazu steht im
Dashboard unter Kanäle, Abschnitt "Fahrzeugliste zum Abholen". Sie enthält
alle veröffentlichten Fahrzeuge in dem Feldformat, das Meta beschreibt.

Ablauf:

1. Meta Business Suite öffnen, Commerce Manager, neuer Katalog vom Typ
   Fahrzeuge
2. Datenquelle: geplanter Feed, die Adresse aus dem Dashboard eintragen
3. Aktualisierung auf täglich stellen

Meta verlangt zusätzlich, dass Händler über einen zugelassenen Partner
inserieren. Ob das für unseren Fall gilt, klärt diese Mail:

> **An:** über den Meta Business Support
> **Betreff:** Fahrzeugkatalog: Anforderungen für Inventory Partner
>
> Guten Tag
>
> Wir entwickeln RapidCar, eine Software für Fahrzeuginserate. Unsere Kunden
> sind Autohäuser und private Verkäufer in der Schweiz und in Deutschland.
>
> Wir stellen einen Fahrzeugkatalog als geplanten Feed bereit, im Feldformat
> Ihrer Dokumentation für Vehicles.
>
> Unsere Fragen:
>
> 1. Können unsere Kunden diesen Feed selbst in ihrem Commerce Manager
>    einrichten, oder ist dafür ein Status als zugelassener Inventory Partner
>    nötig?
> 2. Falls ein Partnerstatus nötig ist: Welche Voraussetzungen gelten, und wie
>    beantragen wir ihn?
> 3. Gelten für Marketplace-Fahrzeuginserate andere Anforderungen als für
>    Automotive Inventory Ads?
>
> Freundliche Grüsse
> [Name, Firma, Telefon]

---

## Kleinanzeigen: läuft über mobile.de

Kleinanzeigen hat keine offene Schnittstelle. mobile.de reicht Inserate
seiner Händler auf Wunsch dorthin weiter; gebucht wird das im
mobile.de-Händlerbereich.

Das heisst: Sobald ein Händler über uns bei mobile.de inseriert und dort die
Weitergabe aktiviert, erscheint das Fahrzeug auch bei Kleinanzeigen. Wir
müssen dafür nichts bauen.

Falls du eine eigene Anbindung willst, gibt es autorisierte
Partnerschnittstellen. Dafür diese Mail:

> **An:** über das Kontaktformular von Kleinanzeigen für gewerbliche Anbieter
> **Betreff:** Schnittstelle für Fahrzeuginserate gewerblicher Anbieter
>
> Guten Tag
>
> Wir entwickeln RapidCar, eine Software für Fahrzeuginserate. Unsere Kunden
> sind Autohäuser, die ihre Fahrzeuge auf mehreren Plattformen anbieten.
>
> Gibt es eine Schnittstelle, über die wir im Namen unserer Kunden
> Fahrzeuginserate bei Kleinanzeigen einstellen und pflegen können? Falls ja,
> welche Voraussetzungen gelten und wie beantragen wir den Zugang?
>
> Freundliche Grüsse
> [Name, Firma, Telefon]

---

## Ricardo: eingebaut, nur der Partnerschlüssel fehlt

Ricardo hat als einzige der übrigen Plattformen eine echte Schnittstelle. Sie
ist vollständig eingebaut: Verbinden über eine Freigabe auf ricardo.ch, ohne
dass ein Händler sein Passwort herausgibt, und Fahrzeuge gehen als
Festpreis-Artikel hinaus.

Was noch fehlt, ist der Partnerschlüssel. Den vergibt Ricardo einmalig an dich
als Anbieter, kostenlos.

> **An:** premiumservice@ricardo.ch, Telefon 041 769 33 44
> **Betreff:** Partnerschlüssel für die Ricardo-Schnittstelle
>
> Guten Tag
>
> Wir entwickeln RapidCar, eine Schweizer Software für Fahrzeuginserate.
> Autohäuser und private Verkäufer erfassen bei uns ihre Fahrzeuge einmal und
> veröffentlichen sie anschliessend auf mehreren Plattformen.
>
> Wir möchten die Ricardo-Schnittstelle nutzen und im Namen unserer Kunden
> Fahrzeuge als Festpreis-Artikel einstellen. Dazu unsere Fragen:
>
> 1. Wie erhalten wir einen Partnerschlüssel (PartnerKey und Passwort)?
> 2. Wir haben den Ablauf so umgesetzt: CreateTemporaryCredential, Freigabe
>    durch den Händler auf ricardo.ch, dann CreateTokenCredential. Ist das
>    richtig?
> 3. Welche Kategorienummer ist für Personenwagen vorgesehen?
> 4. Gibt es eine Testumgebung?
> 5. Welche Anfragegrenzen gelten, und wie viele Bilder sind je Artikel
>    zulässig?
>
> Wir befinden uns noch in der Entwicklung. Unsere Seite rapid-car.com ist
> eine vorläufige Fassung. Echte Artikel haben wir zu keinem Zeitpunkt
> eingestellt.
>
> Freundliche Grüsse
> [Name, Firma, Adresse, Telefon]

Sobald du Schlüssel und Kategorienummer hast, trägst du beides in der
Verwaltung unter Einstellungen ein. Danach steht bei deinen Kunden unter
Kanäle der Verbinden-Knopf.

---

## Autolina (Schweiz)

> **An:** service@autolina.ch
> **Betreff:** Schnittstelle für die Übertragung von Fahrzeuginseraten

> Guten Tag
>
> Wir entwickeln RapidCar, eine Schweizer Software für Fahrzeuginserate.
> Autohäuser und private Verkäufer erfassen bei uns ihre Fahrzeuge einmal und
> veröffentlichen sie anschliessend auf mehreren Plattformen.
>
> Wir möchten Autolina anbinden und haben dazu drei Fragen:
>
> 1. Gibt es eine Schnittstelle, über die wir Fahrzeuge im Namen unserer
>    Kunden einstellen und aktualisieren können?
> 2. Falls Sie stattdessen eine Fahrzeugliste als Datei entgegennehmen:
>    Welches Format erwarten Sie, und in welchem Abstand holen Sie die Datei
>    ab? Wir stellen bereits eine solche Liste bereit und können die Spalten
>    anpassen.
> 3. Welche Voraussetzungen müssen wir als Anbieter erfüllen?
>
> Wir befinden uns noch in der Entwicklung. Unsere Seite rapid-car.com ist
> eine vorläufige Fassung.
>
> Freundliche Grüsse
> [Name, Firma, Adresse, Telefon]

---

## tutti.ch (Schweiz)

tutti.ch gehört wie Ricardo zur Swiss Marketplace Group, hat aber keine
eigene Schnittstelle.

> **An:** hilfe@tutti.ch
> **Betreff:** Schnittstelle für gewerbliche Fahrzeuginserate
>
> Guten Tag
>
> Wir entwickeln RapidCar, eine Schweizer Software für Fahrzeuginserate.
> Unsere Kunden sind Autohäuser und private Verkäufer.
>
> Unsere Fragen:
>
> 1. Gibt es eine Schnittstelle, über die gewerbliche Anbieter ihre Fahrzeuge
>    automatisch einstellen und aktualisieren können?
> 2. Nehmen Sie alternativ eine Fahrzeugliste als Datei entgegen? Welches
>    Format erwarten Sie?
> 3. Wir haben gelesen, dass sich Inserate von tutti.ch automatisch auf
>    Ricardo veröffentlichen lassen. Gilt das auch für Fahrzeuge, die über
>    eine Schnittstelle eingestellt wurden?
>
> Wir befinden uns noch in der Entwicklung. Unsere Seite rapid-car.com ist
> eine vorläufige Fassung.
>
> Freundliche Grüsse
> [Name, Firma, Adresse, Telefon]

---

## Was in der Anwendung passiert

Für jede Plattform ohne Schnittstelle steht auf der Kanalseite ein Knopf
"Anbindung anfragen". Der Kunde meldet damit Interesse, du bekommst eine
Nachricht und die Anfrage steht im Verlauf. So siehst du, welche Plattform
deine Kunden wirklich wollen, bevor du Arbeit hineinsteckst.

Sobald eine Plattform antwortet, sag Bescheid: bei einer Schnittstelle baue
ich sie ein, bei einem Dateiformat passe ich die Spalten der Fahrzeugliste an.
