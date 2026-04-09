# 🗺️ SVG Map Lite

SVG Map Lite is een krachtige, lichtgewicht WordPress-plugin waarmee je interactieve, klikbare SVG-kaarten kunt maken. Upload een afbeelding (zoals een plattegrond van een gebouw of een gebiedskaart), teken interactieve polygonen, en koppel er dynamische data aan via een externe JSON-feed of via handmatige invoer.

## ✨ Features

- **Twee Datamodi:**
- **JSON Modus:** Koppel regio's aan een externe, dynamische JSON-feed (ideaal voor real-time vastgoed beschikbaarheid).
- **Handmatige Modus:** Voer je data direct in de WordPress backend in, zonder dat je een externe feed nodig hebt.
- **Interactieve Polygonen:** Teken eenvoudig klikbare regio's (polygonen) direct over je geüploade afbeelding.
- **Drag & Drop Paneel Bouwer:** Bepaal visueel welke velden uit je data (JSON of handmatig) in het informatiepaneel worden     getoond, inclusief scheidingslijnen.
- **Dynamische Filters:** Genereer automatisch filterbalken (keuzelijsten of schuifregelaars) op basis van je data. Regio's die niet aan de filters voldoen, worden netjes gedimd.
- **Live Preview Styling:** Pas de kleuren, randen, hoekradiussen en de statuskleuren (bijv. 'Beschikbaar' = groen, 'Verkocht' = rood) direct aan in de admin met een real-time preview.
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

## 🛠️ Technische Stack
- Backend: PHP (WordPress Plugin API, Custom Post Types)
- Frontend: Vanilla JS, jQuery (admin), noUiSlider (range filters)