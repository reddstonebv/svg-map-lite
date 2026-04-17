# 🗺️ SVG Map Lite

SVG Map Lite is een krachtige, lichtgewicht WordPress-plugin waarmee je interactieve, klikbare SVG-kaarten kunt maken. Upload een afbeelding (zoals een plattegrond van een gebouw of een gebiedskaart), teken interactieve polygonen, en koppel er dynamische data aan via een externe JSON-feed of via handmatige invoer.

## ✨ Features

- **Twee Datamodi:**
  - **JSON Modus:** Koppel regio's aan een externe, dynamische JSON-feed (ideaal voor real-time vastgoed beschikbaarheid).
  - **Handmatige Modus:** Voer je data direct in de WordPress backend in, zonder dat je een externe feed nodig hebt.
- **Interactieve Polygonen:** Teken eenvoudig klikbare regio's (polygonen) direct over je geüploade afbeelding via een ingebouwde Fabric.js-editor.
- **Multi-Laag Ondersteuning:** Organiseer polygonen over meerdere lagen (bijv. per verdieping of aanzicht), elk met een eigen achtergrondafbeelding en lijnstijl.
- **Multi-View (Many-to-One Mapping):** Koppel meerdere polygonen aan hetzelfde JSON-object — ideaal voor complexe gebouwen waarbij voor-, zij- en bovenaanzicht van één eenheid tegelijk oplichten bij een klik.
- **Drag & Drop Paneel Bouwer:** Bepaal visueel welke velden uit je data in het informatiepaneel worden getoond. Ondersteunt types: Koptekst, Prijs, Tekst, Badge, Link, Afbeelding, HTML (raw), Scheidingslijn, Statische HTML en Statische Knop. Inclusief prefix/suffix-opties per blok (bijv. `€` of `m²`).
- **Resultatenlijst (Overzicht):** Naast het klik-paneel kun je ook een compacte kaartjesweergave onder de kaart activeren die alle regio's als lijstitems toont, eveneens klikbaar en filterbaar.
- **Dynamische Filters:** Genereer automatisch filterbalken op basis van je data. Kies per filter het type: keuzelijst (dropdown), schuifregelaar (range), zoekveld (autocomplete), invoerveld of knoppen. Regio's die niet aan de filters voldoen worden netjes gedimd.
- **Aparte Filters Shortcode:** Plaats de filterbalk los van de kaart via `[svg_map_filters id="123"]`, zodat je de kaart en de filters vrij kunt positioneren op je pagina.
- **Live Preview Styling:** Pas kleuren, randen, hoekradiussen en statuskleuren (bijv. Beschikbaar/Verhuurd/Gereserveerd) direct aan in de admin met een real-time preview.
- **Import / Export:** Exporteer een volledige kaartconfiguratie als JSON en importeer deze op een andere omgeving (bijv. van lokaal naar productie). Media-koppelingen worden leeggelaten zodat je ze handmatig opnieuw kunt koppelen.
- **Shortcode Integratie:** Plaats je kaart overal op je website met een simpele shortcode: `[svg_map id="123"]`.

## 🚀 Installatie

1. Download de nieuwste `.zip` file via de **Releases** tab aan de rechterkant.
2. Ga in je WordPress dashboard naar **Plugins > Nieuwe plugin toevoegen**.
3. Klik op **Plugin uploaden**, selecteer het `.zip` bestand en klik op **Nu installeren**.
4. Activeer de plugin. Je ziet nu "SVG Map Lite" in je WordPress menu verschijnen.

## 📖 Quick Start

1. Ga naar **SVG Map Lite > Nieuwe Toevoegen** en geef je kaart een titel.
2. **Afbeelding & Koppeling:** Upload je basisafbeelding en kies je werkwijze (Handmatig of JSON).
3. Teken je polygonen (klikken om hoekpunten te zetten, klik op het eerste punt om te sluiten).
4. **Data per Vlak:** Vul de data in voor je getekende vlakken, of koppel de unieke ID's aan je JSON-feed.
5. **Paneel Bouwer:** Sleep de gewenste velden in het paneel.
6. **Weergave:** Stel je merkkleuren, statuskleuren en paneel-styling in.
7. Sla de kaart op, kopieer de `[svg_map id="..."]` shortcode rechtsboven en plak deze op een pagina of in een bericht.

## 🆕 Recente Updates

- **Multi-View Ondersteuning (Many-to-One Mapping):** Meerdere SVG-vlakken kunnen aan hetzelfde JSON-object worden gekoppeld. Klik je op een item in de kaart of resultatenlijst, dan lichten alle bijbehorende vlakken tegelijk op. De editor waarschuwt bij dubbele ID's maar biedt een 'Multi-View toestaan' toggle om dit bewust te overschrijven voor multi-layer kaarten.

- **Verbeterde Map Editor Stabiliteit:** De synchronisatie tussen de sidebar, de Fabric.js tekenengine en de WordPress-database is volledig herschreven. Geen race conditions meer bij opslaan via knop of `Cmd+S`. Aangepaste polygon-ID's worden direct en betrouwbaar opgeslagen.

- **Panel Builder Verbeteringen:** Volledig responsive kolombreedtes (geen weggedrukte knoppen meer op kleinere schermen). Een leeg label-veld in de backend resulteert nu in een label-loos blok aan de voorkant — geen automatische terugval op de veldnaam.

- **Quality of Life in Filters:** 'Click-to-Copy' knoppen voor veelgebruikte symbolen (`€`, `m²`) in de Filter Bouwer, met een robuuste fallback voor lokale (niet-HTTPS) omgevingen.

- **Import / Export:** Kaartconfiguraties zijn exporteerbaar als JSON-bestand en importeerbaar op een andere WordPress-installatie. Handig voor migraties tussen lokaal, staging en productie.

## 🛠️ Technische Stack
- Backend: PHP (WordPress Plugin API, Custom Post Types, post meta)
- Teken-editor: Fabric.js
- Frontend: Vanilla JS, jQuery (admin), noUiSlider (range filters)