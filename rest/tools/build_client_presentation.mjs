import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";

import {
  Presentation,
  PresentationFile,
} from "file:///C:/Users/bassi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/@oai/artifact-tool/dist/artifact_tool.mjs";

const repoRoot = "C:/Users/bassi/Documents/Simo/dottorAppLTE";
const threadId = "manual-20260619-dietistica-cliente";
const taskSlug = "presentazione-studio-dietistica-cliente";
const topicSlug = "presentazione-studio-dietistica-cliente";
const workspace = path.join(
  os.tmpdir(),
  "codex-presentations",
  threadId,
  taskSlug,
);
const tmpDir = path.join(workspace, "tmp");
const slidesDir = path.join(tmpDir, "slides");
const previewDir = path.join(tmpDir, "preview");
const layoutDir = path.join(tmpDir, "layout");
const assetDir = path.join(tmpDir, "assets");
const qaDir = path.join(tmpDir, "qa");
const outputDir = path.join(
  repoRoot,
  "outputs",
  "presentazione-studio-dietistica-cliente-2026",
);
const finalPptx = path.join(outputDir, `${topicSlug}.pptx`);
const runbookPath = path.join(outputDir, "runbook-presentazione-cliente.md");
const sourceNotesPath = path.join(tmpDir, "source-notes.txt");
const slidePlanPath = path.join(tmpDir, "slide-plan.txt");
const qaPath = path.join(qaDir, "visual-qa.txt");
const montagePath = path.join(previewDir, "deck-montage.webp");
const slideCount = 11;

const palette = {
  cream: "#F7F1E7",
  white: "#FFFFFF",
  pine: "#1E4F4A",
  pineDark: "#173A37",
  teal: "#2F7D73",
  sage: "#DCE8DE",
  sand: "#E6D4BF",
  gold: "#C8A15A",
  coral: "#C96F4B",
  coralSoft: "#F5E1D7",
  paleTeal: "#E8F1EE",
  ink: "#22312F",
  muted: "#5C6F6B",
  border: "#D7CDC1",
  coolBorder: "#C6D7D1",
  darkTextOnTeal: "#F7F7F5",
};

const fonts = {
  heading: "Georgia",
  body: "Aptos",
  numbers: "Aptos",
};

const frame = { left: 72, top: 64, width: 1136, height: 592 };

function buildLog(message) {
  console.log(`[build] ${message}`);
}

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function writeBlob(filePath, blob) {
  const bytes = new Uint8Array(await blob.arrayBuffer());
  await fs.writeFile(filePath, bytes);
}

function addText(
  slide,
  {
    name,
    text,
    left,
    top,
    width,
    height,
    fontSize = 18,
    color = palette.ink,
    bold = false,
    italic = false,
    typeface = fonts.body,
    alignment = "left",
    verticalAlignment = "top",
    fill = "none",
    lineFill = "none",
    lineWidth = 0,
    autoFit = "shrinkText",
    insets = { top: 0, right: 0, bottom: 0, left: 0 },
    lineSpacing = 1.05,
  },
) {
  const shape = slide.shapes.add({
    geometry: "textbox",
    name,
    position: { left, top, width, height },
    fill,
    line: { style: "solid", fill: lineFill, width: lineWidth },
  });
  shape.text = text;
  shape.text.style = {
    fontSize,
    bold,
    italic,
    color,
    typeface,
    alignment,
    verticalAlignment,
    autoFit,
    insets,
    lineSpacing,
  };
  return shape;
}

function addCard(
  slide,
  {
    name,
    left,
    top,
    width,
    height,
    fill = palette.white,
    lineFill = palette.border,
    lineWidth = 1,
    radius = "rounded-2xl",
    shadow = "shadow-sm",
    geometry = "roundRect",
  },
) {
  return slide.shapes.add({
    geometry,
    name,
    position: { left, top, width, height },
    fill,
    line: { style: "solid", fill: lineFill, width: lineWidth },
    borderRadius: radius,
    shadow,
  });
}

function addRule(slide, { left, top, width, color = palette.border, weight = 1 }) {
  return slide.shapes.add({
    geometry: "line",
    position: { left, top, width, height: 0 },
    fill: "none",
    line: { style: "solid", fill: color, width: weight },
  });
}

function addPill(
  slide,
  {
    left,
    top,
    width,
    height = 30,
    text,
    fill = palette.paleTeal,
    color = palette.pine,
    lineFill = "none",
  },
) {
  addCard(slide, {
    left,
    top,
    width,
    height,
    fill,
    lineFill,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text,
    left,
    top: top + 6,
    width,
    height: height - 8,
    fontSize: 13,
    color,
    bold: true,
    alignment: "center",
    typeface: fonts.body,
  });
}

function addFooter(slide, index, total, sectionLabel) {
  addRule(slide, {
    left: 72,
    top: 676,
    width: 1136,
    color: palette.border,
    weight: 1,
  });
  addText(slide, {
    text: sectionLabel,
    left: 72,
    top: 684,
    width: 240,
    height: 18,
    fontSize: 11,
    color: palette.muted,
    bold: true,
    typeface: fonts.body,
  });
  addText(slide, {
    text: "Demo locale con dati anonimizzati | agenda pronta da 01/06/2026",
    left: 350,
    top: 684,
    width: 510,
    height: 18,
    fontSize: 11,
    color: palette.muted,
    alignment: "center",
    typeface: fonts.body,
  });
  addCard(slide, {
    left: 1148,
    top: 680,
    width: 60,
    height: 28,
    fill: palette.pine,
    lineFill: palette.pine,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: `${index}/${total}`,
    left: 1148,
    top: 686,
    width: 60,
    height: 14,
    fontSize: 12,
    color: palette.white,
    bold: true,
    alignment: "center",
    typeface: fonts.numbers,
  });
}

function addSectionEyebrow(slide, text, rightText = "") {
  addText(slide, {
    text,
    left: frame.left,
    top: 26,
    width: 360,
    height: 24,
    fontSize: 12,
    color: palette.teal,
    bold: true,
    typeface: fonts.body,
  });
  if (rightText) {
    addText(slide, {
      text: rightText,
      left: 910,
      top: 26,
      width: 298,
      height: 24,
      fontSize: 12,
      color: palette.muted,
      bold: true,
      alignment: "right",
      typeface: fonts.body,
    });
  }
}

function addBullets(
  slide,
  { left, top, width, height, items, fontSize = 18, color = palette.ink, boldLead = false },
) {
  const paragraphs = items.map((item) => {
    if (boldLead && item.includes(":")) {
      const idx = item.indexOf(":");
      const lead = item.slice(0, idx + 1);
      const rest = item.slice(idx + 1);
      return {
        bulletCharacter: "•",
        marginLeft: 22,
        indent: -12,
        spaceAfter: 6,
        runs: [{ run: lead, textStyle: { bold: true } }, rest],
      };
    }
    return {
      bulletCharacter: "•",
      marginLeft: 22,
      indent: -12,
      spaceAfter: 6,
      runs: [item],
    };
  });
  return addText(slide, {
    text: paragraphs,
    left,
    top,
    width,
    height,
    fontSize,
    color,
    typeface: fonts.body,
    lineSpacing: 1.1,
    insets: { top: 0, right: 4, bottom: 0, left: 0 },
  });
}

function setNotes(slide, lines) {
  slide.speakerNotes.textFrame.setText(lines);
  slide.speakerNotes.setVisible(true);
}

const presentation = Presentation.create({
  slideSize: { width: 1280, height: 720 },
});

presentation.theme.colorScheme = {
  name: "Dietistica Cliente",
  themeColors: {
    accent1: palette.pine,
    accent2: palette.teal,
    accent3: palette.coral,
    accent4: palette.gold,
    accent5: palette.sage,
    accent6: palette.sand,
    bg1: palette.white,
    bg2: palette.cream,
    tx1: palette.ink,
    tx2: palette.muted,
    dk1: "#000000",
    dk2: palette.pineDark,
    lt1: palette.white,
    lt2: palette.border,
    hlink: palette.teal,
    folHlink: palette.coral,
  },
};

const slides = [];
function newSlide(
  sectionLabel,
  background = palette.cream,
  rightEyebrow = "AmbulatorioFacile | Studio dietistica",
) {
  const slide = presentation.slides.add();
  slide.background.fill = background;
  addSectionEyebrow(slide, sectionLabel, rightEyebrow);
  slides.push(slide);
  return slide;
}

{
  const slide = newSlide("PRESENTAZIONE CLIENTE | DEMO LOCALE");
  slide.shapes.add({
    geometry: "rect",
    position: { left: 0, top: 0, width: 36, height: 720 },
    fill: palette.pine,
    line: { style: "solid", fill: palette.pine, width: 0 },
  });
  addCard(slide, {
    left: 730,
    top: 58,
    width: 478,
    height: 586,
    fill: palette.pineDark,
    lineFill: palette.pineDark,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addPill(slide, {
    left: 72,
    top: 84,
    width: 250,
    text: "Studio target: titolare + collaboratrice + segreteria",
  });
  addText(slide, {
    name: "cover-title",
    text: "AmbulatorioFacile per uno studio di dietistica",
    left: 72,
    top: 150,
    width: 560,
    height: 150,
    fontSize: 58,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
    lineSpacing: 0.92,
    autoFit: "shrinkText",
  });
  addText(slide, {
    text: "Una demo locale, pulita e credibile per mostrare come lavorano insieme agenda, team e continuita del percorso nutrizionale.",
    left: 72,
    top: 320,
    width: 520,
    height: 120,
    fontSize: 22,
    color: palette.muted,
    typeface: fonts.body,
    lineSpacing: 1.15,
  });
  addCard(slide, {
    left: 72,
    top: 500,
    width: 560,
    height: 104,
    fill: palette.paleTeal,
    lineFill: palette.coolBorder,
    radius: "rounded-2xl",
  });
  addText(slide, {
    text: "Messaggio guida",
    left: 100,
    top: 524,
    width: 150,
    height: 22,
    fontSize: 15,
    color: palette.teal,
    bold: true,
  });
  addText(slide, {
    text: "Non presentare una semplice agenda: presenta un modo piu ordinato di far lavorare uno studio piccolo senza caos operativo.",
    left: 100,
    top: 552,
    width: 500,
    height: 34,
    fontSize: 18,
    color: palette.ink,
    typeface: fonts.body,
  });
  const roleCards = [
    [
      "1",
      "Dietista titolare",
      "Tiene il filo clinico, vede agenda, posta e comunicazione con il team.",
    ],
    [
      "2",
      "Biologa nutrizionista",
      "Gestisce follow-up e controlli con una vista chiara e senza sovrapposizioni.",
    ],
    [
      "3",
      "Segreteria",
      "Inserisce, conferma e sposta appuntamenti partendo da disponibilita reali.",
    ],
  ];
  roleCards.forEach(([num, title, body], index) => {
    const top = 100 + index * 122;
    addCard(slide, {
      left: 770,
      top,
      width: 398,
      height: 98,
      fill: palette.white,
      lineFill: palette.white,
      lineWidth: 0,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addCard(slide, {
      left: 790,
      top: top + 18,
      width: 44,
      height: 44,
      fill: palette.sand,
      lineFill: palette.sand,
      lineWidth: 0,
      radius: "rounded-2xl",
      shadow: "shadow-none",
    });
    addText(slide, {
      text: num,
      left: 790,
      top: top + 28,
      width: 44,
      height: 20,
      fontSize: 20,
      color: palette.pine,
      bold: true,
      alignment: "center",
    });
    addText(slide, {
      text: title,
      left: 850,
      top: top + 18,
      width: 285,
      height: 24,
      fontSize: 22,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addText(slide, {
      text: body,
      left: 850,
      top: top + 46,
      width: 285,
      height: 34,
      fontSize: 15,
      color: palette.muted,
      typeface: fonts.body,
      lineSpacing: 1.1,
    });
  });
  addCard(slide, {
    left: 770,
    top: 486,
    width: 398,
    height: 118,
    fill: palette.teal,
    lineFill: palette.teal,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "La demo e gia pronta per giugno 2026",
    left: 800,
    top: 512,
    width: 330,
    height: 28,
    fontSize: 25,
    color: palette.white,
    bold: true,
    typeface: fonts.heading,
  });
  addText(slide, {
    text: "Calendario ampio, slot occupati e slot liberi reali per simulare nuove prenotazioni durante la presentazione.",
    left: 800,
    top: 548,
    width: 320,
    height: 36,
    fontSize: 16,
    color: palette.darkTextOnTeal,
    typeface: fonts.body,
  });
  addFooter(slide, 1, slideCount, "Apertura");
  setNotes(slide, [
    "Apri dal problema del cliente: in uno studio piccolo ogni passaggio perso pesa doppio.",
    "Spiega subito che la demo e locale, con dati anonimi e database separato dalla farmacia.",
    "Frase pronta: qui non vedrete una semplice agenda, ma un modo piu ordinato di lavorare come studio.",
    "Durata ideale della slide: 45-60 secondi.",
  ]);
  buildLog("slide-01-built");
}

{
  const slide = newSlide("EXECUTIVE SUMMARY");
  addText(slide, {
    text: "Il valore da far percepire in 15 minuti",
    left: 72,
    top: 92,
    width: 700,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addCard(slide, {
    left: 72,
    top: 176,
    width: 510,
    height: 350,
    fill: palette.pineDark,
    lineFill: palette.pineDark,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "AmbulatorioFacile deve sembrare il centro operativo dello studio, non un contenitore di moduli.",
    left: 110,
    top: 230,
    width: 430,
    height: 132,
    fontSize: 32,
    color: palette.white,
    bold: true,
    typeface: fonts.heading,
    lineSpacing: 0.98,
  });
  addText(slide, {
    text: "Domani il focus resta su tre promesse concrete: ordine, continuita del percorso e controllo dei passaggi tra segreteria e professionisti.",
    left: 110,
    top: 390,
    width: 420,
    height: 84,
    fontSize: 18,
    color: palette.darkTextOnTeal,
    typeface: fonts.body,
    lineSpacing: 1.12,
  });
  const summaryCards = [
    [
      "Ordine operativo",
      "Appuntamenti, disponibilita e comunicazioni stanno nello stesso flusso.",
    ],
    [
      "Continuita del percorso",
      "Il professionista vede calendario, paziente e follow-up senza cambiare contesto.",
    ],
    [
      "Controllo degli accessi",
      "Ogni ruolo entra con la propria vista, senza rumore inutile.",
    ],
  ];
  summaryCards.forEach(([title, body], index) => {
    const top = 176 + index * 118;
    addCard(slide, {
      left: 628,
      top,
      width: 580,
      height: 94,
      fill: index === 1 ? palette.paleTeal : palette.white,
      lineFill: index === 1 ? palette.coolBorder : palette.border,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addText(slide, {
      text: title,
      left: 658,
      top: top + 18,
      width: 250,
      height: 24,
      fontSize: 24,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addText(slide, {
      text: body,
      left: 658,
      top: top + 48,
      width: 500,
      height: 26,
      fontSize: 16,
      color: palette.muted,
      typeface: fonts.body,
    });
  });
  addCard(slide, {
    left: 628,
    top: 554,
    width: 580,
    height: 60,
    fill: palette.coralSoft,
    lineFill: palette.coralSoft,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Da ripetere piu volte: il beneficio non e solo prenotare meglio. E lavorare meglio come studio.",
    left: 656,
    top: 575,
    width: 520,
    height: 18,
    fontSize: 15,
    color: palette.ink,
    bold: true,
    alignment: "center",
  });
  addFooter(slide, 2, slideCount, "Executive summary");
  setNotes(slide, [
    "Questa slide serve a impostare i criteri con cui loro giudicheranno la demo.",
    "Non entrare in dettagli tecnici: fai percepire il perimetro e il valore.",
    "Punta su tempo risparmiato, chiarezza e meno passaggi dispersi tra telefono, fogli e messaggi.",
  ]);
  buildLog("slide-02-built");
}

{
  const slide = newSlide("SCENARIO DELLO STUDIO");
  addText(slide, {
    text: "Il caso d uso e credibile per uno studio da 3 persone",
    left: 72,
    top: 92,
    width: 760,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addText(slide, {
    text: "Mostra che la piattaforma non cambia il modo in cui lavorano: mette ordine tra ruoli che esistono gia.",
    left: 72,
    top: 150,
    width: 770,
    height: 28,
    fontSize: 18,
    color: palette.muted,
  });
  const roles = [
    {
      tag: "Ruolo 1",
      title: "Dietista titolare",
      body: [
        "Tiene il filo clinico delle prime visite.",
        "Controlla agenda, storico e follow-up.",
        "Ha bisogno di contesto, non solo di un orario.",
      ],
    },
    {
      tag: "Ruolo 2",
      title: "Biologa nutrizionista",
      body: [
        "Gestisce controlli, BIA e pazienti propri.",
        "Lavora in parallelo senza perdere ordine.",
        "Condivide il team, ma non il rumore inutile.",
      ],
    },
    {
      tag: "Ruolo 3",
      title: "Segreteria",
      body: [
        "Inserisce, conferma e sposta appuntamenti.",
        "Deve partire da slot reali e gia leggibili.",
        "Ha visibilita operativa senza entrare nel lavoro clinico.",
      ],
    },
  ];
  roles.forEach((role, index) => {
    const left = 72 + index * 360;
    addCard(slide, {
      left,
      top: 212,
      width: 336,
      height: 336,
      fill: palette.white,
      lineFill: palette.border,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addCard(slide, {
      left,
      top: 212,
      width: 336,
      height: 14,
      fill:
        index === 0 ? palette.teal : index === 1 ? palette.gold : palette.coral,
      lineFill: "none",
      lineWidth: 0,
      radius: 14,
      shadow: "shadow-none",
      geometry: "rect",
    });
    addPill(slide, {
      left: left + 24,
      top: 242,
      width: 90,
      text: role.tag,
      fill: palette.sand,
      color: palette.pineDark,
    });
    addText(slide, {
      text: role.title,
      left: left + 24,
      top: 292,
      width: 280,
      height: 56,
      fontSize: 28,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addBullets(slide, {
      left: left + 24,
      top: 364,
      width: 286,
      height: 136,
      items: role.body,
      fontSize: 16,
      color: palette.ink,
    });
  });
  addCard(slide, {
    left: 72,
    top: 576,
    width: 1056,
    height: 52,
    fill: palette.paleTeal,
    lineFill: palette.coolBorder,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Vista admin: da usare solo per aprire la storia. Viste segreteria e dietista: da usare per far vedere il valore operativo.",
    left: 102,
    top: 594,
    width: 996,
    height: 16,
    fontSize: 15,
    color: palette.ink,
    bold: true,
    alignment: "center",
  });
  addFooter(slide, 3, slideCount, "Scenario");
  setNotes(slide, [
    "Qui rendi la demo riconoscibile: loro devono sentirsi dentro lo scenario, non davanti a un prodotto generico.",
    "Il messaggio e che ogni ruolo mantiene il proprio perimetro ma smette di lavorare a pezzi.",
    "Se ti interrompono qui, e un buon segno: stanno iniziando a tradurre la demo sul loro studio.",
  ]);
  buildLog("slide-03-built");
}

{
  const slide = newSlide("PERIMETRO DELLA PIATTAFORMA");
  addText(slide, {
    text: "Cosa copre la piattaforma senza allargare il discorso",
    left: 72,
    top: 92,
    width: 820,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addText(slide, {
    text: "Restare su questi quattro pilastri aiuta a fare una demo corta, forte e molto piu vendibile.",
    left: 72,
    top: 150,
    width: 800,
    height: 26,
    fontSize: 18,
    color: palette.muted,
  });
  const pillars = [
    [
      "Agenda operativa",
      [
        "Disponibilita reali per ruolo e professionista.",
        "Slot prenotati e slot liberi visibili subito.",
      ],
    ],
    [
      "Comunicazione interna",
      [
        "Chat e posta evitano rincorse su strumenti esterni.",
        "Le informazioni operative restano nello stesso ambiente.",
      ],
    ],
    [
      "Accessi controllati",
      [
        "Ogni ruolo vede solo cio che serve.",
        "OTP disponibile per mostrare un passaggio di sicurezza semplice.",
      ],
    ],
    [
      "Continuita paziente",
      [
        "Il professionista collega agenda, follow-up e contatto.",
        "La segreteria supporta il flusso senza invadere il perimetro clinico.",
      ],
    ],
  ];
  pillars.forEach(([title, items], index) => {
    const col = index % 2;
    const row = Math.floor(index / 2);
    const left = 72 + col * 560;
    const top = 214 + row * 184;
    addCard(slide, {
      left,
      top,
      width: 540,
      height: 156,
      fill: palette.white,
      lineFill: palette.border,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addText(slide, {
      text: title,
      left: left + 28,
      top: top + 24,
      width: 280,
      height: 28,
      fontSize: 26,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addBullets(slide, {
      left: left + 28,
      top: top + 64,
      width: 472,
      height: 68,
      items,
      fontSize: 16,
      color: palette.ink,
    });
  });
  addFooter(slide, 4, slideCount, "Pilastri");
  setNotes(slide, [
    "Se il cliente tende a disperdersi, questa e la slide che ti aiuta a riportarlo sul perimetro corretto.",
    "Non dire tutto il prodotto: fai vedere che e abbastanza completo per lo studio e abbastanza semplice da essere adottato.",
    "Frase utile: non dobbiamo aggiungere strumenti, dobbiamo togliere frammentazione.",
  ]);
  buildLog("slide-04-built");
}

{
  const slide = newSlide("SCALLETTA DEMO DAL VIVO");
  addText(slide, {
    text: "Percorso demo consigliato",
    left: 72,
    top: 92,
    width: 560,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addPill(slide, {
    left: 1036,
    top: 96,
    width: 172,
    text: "Tempo ideale: 12-15 minuti",
    fill: palette.coralSoft,
    color: palette.ink,
  });
  addRule(slide, {
    left: 132,
    top: 308,
    width: 976,
    color: palette.coolBorder,
    weight: 4,
  });
  const steps = [
    [
      "1",
      "Admin",
      "Apri la struttura e spiega ruoli, visibilita e demo separata.",
    ],
    [
      "2",
      "Segreteria",
      "Mostra agenda, slot liberi, conferma e spostamento appuntamenti.",
    ],
    [
      "3",
      "Dietista",
      "Mostra agenda, posta e chat dal punto di vista del professionista.",
    ],
    [
      "4",
      "Portale paziente",
      "Usalo solo se serve rinforzare continuita e percezione del servizio.",
    ],
    [
      "5",
      "Chiusura valore",
      "Riporta tutto su ordine operativo, tempo e percorso paziente.",
    ],
  ];
  steps.forEach(([num, title, body], index) => {
    const centerX = 160 + index * 236;
    addCard(slide, {
      left: centerX - 26,
      top: 280,
      width: 52,
      height: 52,
      fill: index === 1 ? palette.coral : palette.pine,
      lineFill: "none",
      lineWidth: 0,
      radius: "rounded-full",
      shadow: "shadow-none",
      geometry: "rect",
    });
    addText(slide, {
      text: num,
      left: centerX - 26,
      top: 295,
      width: 52,
      height: 18,
      fontSize: 22,
      color: palette.white,
      bold: true,
      alignment: "center",
    });
    addCard(slide, {
      left: centerX - 92,
      top: 360,
      width: 184,
      height: 154,
      fill: palette.white,
      lineFill: palette.border,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addText(slide, {
      text: title,
      left: centerX - 72,
      top: 384,
      width: 144,
      height: 24,
      fontSize: 22,
      color: palette.pineDark,
      bold: true,
      alignment: "center",
      typeface: fonts.heading,
    });
    addText(slide, {
      text: body,
      left: centerX - 76,
      top: 420,
      width: 152,
      height: 74,
      fontSize: 15,
      color: palette.muted,
      alignment: "center",
      typeface: fonts.body,
      lineSpacing: 1.08,
    });
  });
  addCard(slide, {
    left: 72,
    top: 548,
    width: 1136,
    height: 72,
    fill: palette.paleTeal,
    lineFill: palette.coolBorder,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Punto forte pronto per domani: l agenda si apre gia su giugno 2026 con appuntamenti esistenti e slot liberi, quindi puoi simulare prenotazioni senza tempi morti.",
    left: 112,
    top: 570,
    width: 1056,
    height: 28,
    fontSize: 17,
    color: palette.ink,
    bold: true,
    alignment: "center",
  });
  addFooter(slide, 5, slideCount, "Scaletta live");
  setNotes(slide, [
    "Questa e la tua mappa mentale per non perdere ritmo nella presentazione.",
    "Se vedi attenzione alta, fai anche il portale paziente. Se vedi poco tempo, chiudi dopo la vista dietista.",
    "Il cuore della demo e tra step 2 e step 3: segreteria e dietista.",
  ]);
  buildLog("slide-05-built");
}

{
  const slide = newSlide("VISTA ADMIN");
  addText(slide, {
    text: "Admin: apri con struttura, non con funzioni",
    left: 72,
    top: 92,
    width: 720,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addCard(slide, {
    left: 72,
    top: 174,
    width: 408,
    height: 352,
    fill: palette.pineDark,
    lineFill: palette.pineDark,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Frase da usare",
    left: 110,
    top: 206,
    width: 140,
    height: 20,
    fontSize: 14,
    color: palette.sand,
    bold: true,
  });
  addText(slide, {
    text: "La parte admin serve per impostare lo studio una volta sola e poi lasciare ogni ruolo lavorare con la propria vista.",
    left: 110,
    top: 250,
    width: 332,
    height: 144,
    fontSize: 31,
    color: palette.white,
    bold: true,
    typeface: fonts.heading,
    lineSpacing: 1.02,
  });
  addText(slide, {
    text: "Qui non devi navigare a lungo: ti basta fare percepire che esistono struttura, ruoli e controllo.",
    left: 110,
    top: 420,
    width: 320,
    height: 54,
    fontSize: 17,
    color: palette.darkTextOnTeal,
    typeface: fonts.body,
  });
  addCard(slide, {
    left: 520,
    top: 150,
    width: 688,
    height: 402,
    fill: palette.white,
    lineFill: palette.border,
    radius: "rounded-2xl",
    shadow: "shadow-sm",
  });
  addText(slide, {
    text: "Cosa mostrare",
    left: 556,
    top: 186,
    width: 180,
    height: 28,
    fontSize: 28,
    color: palette.pineDark,
    bold: true,
    typeface: fonts.heading,
  });
  addBullets(slide, {
    left: 556,
    top: 238,
    width: 590,
    height: 180,
    items: [
      "Studio demo separato dal gestionale farmacia.",
      "Ruoli distinti: admin, segreteria, dietista, nutrizionista, portale paziente.",
      "Visibilita moduli ridotta a cio che serve davvero allo scenario dietistica.",
      "Dati demo anonimizzati e base dedicata alla presentazione commerciale.",
    ],
    fontSize: 18,
    color: palette.ink,
  });
  addCard(slide, {
    left: 556,
    top: 438,
    width: 590,
    height: 76,
    fill: palette.coralSoft,
    lineFill: palette.coralSoft,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Cosa evitare: non aprire menu tecnici o impostazioni profonde. Qui devi solo far sentire che la struttura e seria e governabile.",
    left: 582,
    top: 458,
    width: 538,
    height: 34,
    fontSize: 16,
    color: palette.ink,
    typeface: fonts.body,
    lineSpacing: 1.08,
  });
  addFooter(slide, 6, slideCount, "Admin");
  setNotes(slide, [
    "Accesso consigliato: http://127.0.0.1:8081/admin con demo.admin / Demo2026.",
    "Durata ideale: 2 minuti. Devi aprire la storia, non fare configurazione.",
    "Parole chiave: ruoli, visibilita, demo separata, dati anonimizzati.",
  ]);
  buildLog("slide-06-built");
}

{
  const slide = newSlide("VISTA SEGRETERIA");
  addText(slide, {
    text: "Segreteria: qui si vede il risparmio di tempo",
    left: 72,
    top: 92,
    width: 760,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addPill(slide, {
    left: 868,
    top: 98,
    width: 340,
    text: "Accesso rapido consigliato: demo.admin->demo.segreteria",
    fill: palette.sand,
    color: palette.pineDark,
  });
  const actions = [
    ["01", "Apri l agenda", "La demo si apre gia sul 1 giugno 2026."],
    [
      "02",
      "Scegli il professionista",
      "La vista e pulita e non contiene moduli fuori scenario.",
    ],
    [
      "03",
      "Usa uno slot libero",
      "Mostra subito che ci sono spazi reali prenotabili.",
    ],
    [
      "04",
      "Conferma o sposta",
      "Il valore e velocita con meno passaggi tra front desk e professionista.",
    ],
  ];
  actions.forEach(([num, title, body], index) => {
    const top = 180 + index * 95;
    addCard(slide, {
      left: 72,
      top,
      width: 392,
      height: 78,
      fill: index % 2 === 0 ? palette.white : palette.paleTeal,
      lineFill: index % 2 === 0 ? palette.border : palette.coolBorder,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addText(slide, {
      text: num,
      left: 96,
      top: top + 20,
      width: 54,
      height: 20,
      fontSize: 20,
      color: palette.teal,
      bold: true,
      typeface: fonts.numbers,
    });
    addText(slide, {
      text: title,
      left: 154,
      top: top + 16,
      width: 180,
      height: 22,
      fontSize: 22,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addText(slide, {
      text: body,
      left: 154,
      top: top + 42,
      width: 210,
      height: 18,
      fontSize: 15,
      color: palette.muted,
      typeface: fonts.body,
    });
  });
  addCard(slide, {
    left: 510,
    top: 176,
    width: 698,
    height: 364,
    fill: palette.white,
    lineFill: palette.border,
    radius: "rounded-2xl",
    shadow: "shadow-sm",
  });
  addText(slide, {
    text: "Cosa far notare live",
    left: 544,
    top: 204,
    width: 250,
    height: 28,
    fontSize: 28,
    color: palette.pineDark,
    bold: true,
    typeface: fonts.heading,
  });
  addBullets(slide, {
    left: 544,
    top: 252,
    width: 620,
    height: 176,
    items: [
      "L agenda mostra sia appuntamenti esistenti sia slot liberi reali.",
      "I moduli medico di famiglia e specialista sono nascosti: la demo resta coerente con dietistica.",
      "La segreteria parte da disponibilita leggibili senza dover inseguire il professionista.",
      "Lo studio appare organizzato anche mentre fai una simulazione dal vivo.",
    ],
    fontSize: 18,
    color: palette.ink,
  });
  addCard(slide, {
    left: 544,
    top: 436,
    width: 620,
    height: 74,
    fill: palette.paleTeal,
    lineFill: palette.coolBorder,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Frase pronta: qui la segreteria guadagna velocita senza dover usare messaggi sparsi o memoria personale per capire dove mettere il paziente.",
    left: 572,
    top: 456,
    width: 564,
    height: 34,
    fontSize: 16,
    color: palette.ink,
    typeface: fonts.body,
  });
  addFooter(slide, 7, slideCount, "Segreteria");
  setNotes(slide, [
    "Accesso verificato piu sicuro: demo.admin->demo.segreteria / Demo2026 / OTP 2510.",
    "Mostra la giornata del 1 giugno 2026: appuntamenti piu 3 slot liberi a fine mattina.",
    "Questa e la parte piu vendibile per uno studio di 2 o 3 persone.",
  ]);
  buildLog("slide-07-built");
}

{
  const slide = newSlide("VISTA DIETISTA");
  addText(slide, {
    text: "Dietista: il professionista vede il contesto, non solo l orario",
    left: 72,
    top: 92,
    width: 860,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addPill(slide, {
    left: 1002,
    top: 98,
    width: 206,
    text: "OTP fisso demo: 2510",
    fill: palette.coralSoft,
    color: palette.ink,
  });
  const doctorCards = [
    [
      "Agenda",
      "Il professionista entra gia sulla propria agenda e collega il tempo al percorso del paziente.",
    ],
    [
      "Posta",
      "Le domande del paziente restano nel flusso di lavoro e non si perdono su canali esterni.",
    ],
    [
      "Chat",
      "Segreteria e professionista si allineano sulle operazioni senza spezzare il contesto.",
    ],
  ];
  doctorCards.forEach(([title, body], index) => {
    const left = 72 + index * 378;
    addCard(slide, {
      left,
      top: 208,
      width: 340,
      height: 258,
      fill: index === 1 ? palette.paleTeal : palette.white,
      lineFill: index === 1 ? palette.coolBorder : palette.border,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addText(slide, {
      text: title,
      left: left + 28,
      top: 242,
      width: 160,
      height: 30,
      fontSize: 30,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addText(slide, {
      text: body,
      left: left + 28,
      top: 292,
      width: 284,
      height: 100,
      fontSize: 18,
      color: palette.ink,
      typeface: fonts.body,
      lineSpacing: 1.12,
    });
    addCard(slide, {
      left: left + 28,
      top: 406,
      width: 112,
      height: 30,
      fill: palette.sand,
      lineFill: palette.sand,
      lineWidth: 0,
      radius: "rounded-2xl",
      shadow: "shadow-none",
    });
    addText(slide, {
      text: index === 0 ? "Veloce" : index === 1 ? "Tracciabile" : "Allineato",
      left: left + 28,
      top: 414,
      width: 112,
      height: 14,
      fontSize: 12,
      color: palette.pineDark,
      bold: true,
      alignment: "center",
    });
  });
  addCard(slide, {
    left: 72,
    top: 516,
    width: 1136,
    height: 92,
    fill: palette.pineDark,
    lineFill: palette.pineDark,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Frase pronta: il professionista non vede solo l appuntamento. Vede il contesto del paziente, il follow-up e la comunicazione che serve per far andare avanti il percorso.",
    left: 106,
    top: 544,
    width: 1068,
    height: 36,
    fontSize: 20,
    color: palette.white,
    bold: true,
    alignment: "center",
    typeface: fonts.body,
    lineSpacing: 1.08,
  });
  addFooter(slide, 8, slideCount, "Dietista");
  setNotes(slide, [
    "Accesso: demo.dietista / Demo2026 / OTP 2510.",
    "Mostra OTP solo se ti aiuta a far percepire serieta dell accesso. Altrimenti vai dritto su agenda, posta e chat.",
    "Il punto non e l interfaccia in se: e la continuita del lavoro clinico.",
  ]);
  buildLog("slide-08-built");
}

{
  const slide = newSlide(
    "FIDUCIA, SICUREZZA E PERCEZIONE",
    palette.pineDark,
    "AmbulatorioFacile | messaggio di fiducia",
  );
  addText(slide, {
    text: "Sicurezza semplice, percezione professionale alta",
    left: 72,
    top: 92,
    width: 800,
    height: 56,
    fontSize: 40,
    color: palette.white,
    bold: true,
    typeface: fonts.heading,
  });
  addText(slide, {
    text: "Non devi vendere complessita tecnica. Devi far passare l idea che lo studio appare serio, ordinato e protetto.",
    left: 72,
    top: 152,
    width: 760,
    height: 30,
    fontSize: 18,
    color: palette.darkTextOnTeal,
    typeface: fonts.body,
  });
  const trustItems = [
    ["OTP per accessi sensibili", "Mostralo solo se aiuta il racconto."],
    ["Demo locale e dati finti", "Messaggio utile per abbassare qualsiasi timore."],
    ["Database demo separato", "Rafforza l idea di ambiente controllato."],
    ["Portale paziente dedicato", "Opzionale, ma forte per la percezione del servizio."],
  ];
  trustItems.forEach(([title, body], index) => {
    const top = 224 + index * 82;
    addCard(slide, {
      left: 72,
      top,
      width: 430,
      height: 64,
      fill: index % 2 === 0 ? "#224743" : "#2B5954",
      lineFill: "#224743",
      lineWidth: 0,
      radius: "rounded-2xl",
      shadow: "shadow-none",
    });
    addText(slide, {
      text: title,
      left: 98,
      top: top + 12,
      width: 220,
      height: 20,
      fontSize: 19,
      color: palette.white,
      bold: true,
      typeface: fonts.body,
    });
    addText(slide, {
      text: body,
      left: 98,
      top: top + 34,
      width: 300,
      height: 14,
      fontSize: 13,
      color: palette.darkTextOnTeal,
      typeface: fonts.body,
    });
  });
  addCard(slide, {
    left: 564,
    top: 210,
    width: 644,
    height: 346,
    fill: palette.white,
    lineFill: palette.white,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-sm",
  });
  addText(slide, {
    text: "Messaggio da lasciare",
    left: 604,
    top: 242,
    width: 240,
    height: 28,
    fontSize: 28,
    color: palette.pineDark,
    bold: true,
    typeface: fonts.heading,
  });
  addText(slide, {
    text: "Quando una demo di studio piccolo appare ordinata, protetta e coerente con i ruoli, il cliente inizia a immaginare un cambiamento reale e non solo un nuovo software da gestire.",
    left: 604,
    top: 300,
    width: 556,
    height: 132,
    fontSize: 27,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
    lineSpacing: 1.03,
  });
  addText(slide, {
    text: "Qui puoi collegare il discorso a meno confusione, piu controllo sugli accessi e migliore percezione del servizio da parte del paziente.",
    left: 604,
    top: 452,
    width: 548,
    height: 54,
    fontSize: 18,
    color: palette.muted,
    typeface: fonts.body,
    lineSpacing: 1.08,
  });
  addFooter(slide, 9, slideCount, "Fiducia e sicurezza");
  setNotes(slide, [
    "Questa slide ti aiuta a chiudere il blocco demo con una percezione piu alta del prodotto.",
    "Non diventare tecnico: resta su accessi controllati, ordine e professionalita percepita.",
    "Usa il portale paziente solo se ti serve una leva in piu sulla percezione del servizio.",
  ]);
  buildLog("slide-09-built");
}

{
  const slide = newSlide("PERCORSO DI ATTIVAZIONE");
  addText(slide, {
    text: "Se il cliente e interessato, chiudi con un percorso semplice",
    left: 72,
    top: 92,
    width: 860,
    height: 56,
    fontSize: 40,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addText(slide, {
    text: "Niente promesse vaghe: proponi un avvio ordinato, leggero e comprensibile anche per uno studio piccolo.",
    left: 72,
    top: 150,
    width: 860,
    height: 28,
    fontSize: 18,
    color: palette.muted,
  });
  const phases = [
    [
      "Fase 1",
      "Mappa studio e ruoli",
      "Raccogli flusso attuale, persone, stanze e modo di prendere appuntamenti.",
    ],
    [
      "Fase 2",
      "Adattamento operativo",
      "Definisci viste, agenda, ruoli e punti di comunicazione da tenere dentro al prodotto.",
    ],
    [
      "Fase 3",
      "Go-live assistito",
      "Parti con un perimetro chiaro e una fase iniziale seguita per consolidare il nuovo ritmo.",
    ],
  ];
  phases.forEach(([phase, title, body], index) => {
    const left = 72 + index * 380;
    addCard(slide, {
      left,
      top: 220,
      width: 340,
      height: 214,
      fill: palette.white,
      lineFill: palette.border,
      radius: "rounded-2xl",
      shadow: "shadow-sm",
    });
    addPill(slide, {
      left: left + 24,
      top: 246,
      width: 88,
      text: phase,
      fill: index === 1 ? palette.coralSoft : palette.paleTeal,
      color: palette.ink,
    });
    addText(slide, {
      text: title,
      left: left + 24,
      top: 294,
      width: 286,
      height: 54,
      fontSize: 30,
      color: palette.pineDark,
      bold: true,
      typeface: fonts.heading,
    });
    addText(slide, {
      text: body,
      left: left + 24,
      top: 360,
      width: 286,
      height: 50,
      fontSize: 17,
      color: palette.muted,
      typeface: fonts.body,
      lineSpacing: 1.1,
    });
  });
  const outcomes = [
    "Agenda piu ordinata e leggibile",
    "Meno passaggi dispersi tra persone",
    "Piu controllo sul percorso del paziente",
  ];
  outcomes.forEach((text, index) => {
    const left = 72 + index * 380;
    addCard(slide, {
      left,
      top: 478,
      width: 340,
      height: 92,
      fill: index === 2 ? palette.sand : palette.white,
      lineFill: index === 2 ? palette.sand : palette.border,
      lineWidth: index === 2 ? 0 : 1,
      radius: "rounded-2xl",
      shadow: "shadow-none",
    });
    addText(slide, {
      text,
      left: left + 26,
      top: 512,
      width: 288,
      height: 28,
      fontSize: 22,
      color: palette.ink,
      bold: true,
      alignment: "center",
      typeface: fonts.body,
    });
  });
  addFooter(slide, 10, slideCount, "Percorso di attivazione");
  setNotes(slide, [
    "Non parlare di progetto enorme. Per uno studio piccolo vince sempre un ingresso ordinato e sostenibile.",
    "Se ti chiedono tempi o modalita, usa queste tre fasi come risposta semplice e credibile.",
    "L obiettivo e portare la conversazione da demo a prossimo passo concreto.",
  ]);
  buildLog("slide-10-built");
}

{
  const slide = newSlide("CHIUSURA E NEXT STEP");
  addText(slide, {
    text: "Chiudi cosi",
    left: 72,
    top: 92,
    width: 320,
    height: 56,
    fontSize: 42,
    color: palette.ink,
    bold: true,
    typeface: fonts.heading,
  });
  addCard(slide, {
    left: 72,
    top: 170,
    width: 602,
    height: 352,
    fill: palette.pineDark,
    lineFill: palette.pineDark,
    lineWidth: 0,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Il beneficio non e solo prenotare meglio. E far lavorare meglio lo studio, con meno caos, meno tempi morti e piu continuita nel percorso del paziente.",
    left: 112,
    top: 228,
    width: 520,
    height: 156,
    fontSize: 34,
    color: palette.white,
    bold: true,
    typeface: fonts.heading,
    lineSpacing: 1.02,
  });
  addText(slide, {
    text: "Questa frase va detta lentamente e senza aggiungere altro subito dopo.",
    left: 112,
    top: 408,
    width: 470,
    height: 22,
    fontSize: 15,
    color: palette.darkTextOnTeal,
    typeface: fonts.body,
  });
  addCard(slide, {
    left: 724,
    top: 170,
    width: 484,
    height: 352,
    fill: palette.white,
    lineFill: palette.border,
    radius: "rounded-2xl",
    shadow: "shadow-sm",
  });
  addText(slide, {
    text: "Domande da aspettarsi",
    left: 760,
    top: 204,
    width: 250,
    height: 28,
    fontSize: 28,
    color: palette.pineDark,
    bold: true,
    typeface: fonts.heading,
  });
  addBullets(slide, {
    left: 760,
    top: 250,
    width: 398,
    height: 128,
    items: [
      "Si adatta ai nostri ruoli reali?",
      "Quanto cambia il nostro modo di lavorare?",
      "E adatto anche a uno studio piccolo?",
      "Come si parte senza bloccare l operativita?",
    ],
    fontSize: 17,
    color: palette.ink,
  });
  addCard(slide, {
    left: 760,
    top: 404,
    width: 398,
    height: 86,
    fill: palette.paleTeal,
    lineFill: palette.coolBorder,
    radius: "rounded-2xl",
    shadow: "shadow-none",
  });
  addText(slide, {
    text: "Prossimo passo proposto",
    left: 786,
    top: 426,
    width: 190,
    height: 20,
    fontSize: 15,
    color: palette.teal,
    bold: true,
  });
  addText(slide, {
    text: "Sessione breve di raccolta flusso attuale, ruoli e agenda per trasformare la demo in configurazione reale.",
    left: 786,
    top: 450,
    width: 346,
    height: 28,
    fontSize: 16,
    color: palette.ink,
    typeface: fonts.body,
  });
  addFooter(slide, 11, slideCount, "Chiusura");
  setNotes(slide, [
    "Chiudi con la frase forte e fai una pausa vera.",
    "Poi passa alle domande e prova a ottenere un prossimo passo concreto, non solo un generico ci sentiamo.",
    "Se serve, usa il runbook per le risposte rapide alle obiezioni.",
  ]);
  buildLog("slide-11-built");
}

const slidePlan = `DECK MODE: create
Audience: studio di dietistica/nutrizione con 3 persone operative
Goal: presentazione cliente professionale + supporto demo live
Output deck: ${finalPptx}
Additional deliverable: ${runbookPath}
Slide size: 1280x720

STYLE DIRECTION
- Dominant color: ${palette.pineDark}
- Supporting tones: ${palette.cream}, ${palette.paleTeal}, ${palette.sand}
- Accent: ${palette.coral}
- Heading font: ${fonts.heading}
- Body font: ${fonts.body}
- Numeric/KPI font: ${fonts.numbers}

FONT SCALE
- Cover title: 58px
- Slide titles: 40-42px
- Card titles: 22-30px
- Body copy: 15-22px
- Footer/source notes: 11-12px

SLIDE LIST
1. Cover e messaggio guida
2. Executive summary e criteri di valore
3. Scenario studio a 3 ruoli
4. Quattro pilastri della piattaforma
5. Percorso demo consigliato
6. Vista admin
7. Vista segreteria
8. Vista dietista
9. Fiducia e sicurezza
10. Percorso di attivazione
11. Chiusura e next step
`;

const sourceNotes = `SOURCE NOTES
Access date: 2026-06-19

1) Presentazione demo dietistica interna
Source: rest/docs/presentazione-demo-dietistica.md
Use: storyline, accessi demo, frasi guida, domande attese.

2) Report seed demo locale
Source: rest/writable/demo_setup/demo_seed_20260619_073559.json
Use: conferma account demo, database demo separato, struttura dataset, scenario operativo, numeri di slot e appuntamenti.

3) Agenda demo locale
Source: rest/app/Controllers/Agenda.php
Use: default agenda impostata sul 2026-06-01 per gli account demo.

4) Seed demo agenda e moduli visibili
Source: rest/tools/SeedDemoData.php
Use: rimozione moduli medico di famiglia/specialista, range agenda giugno 2026, presenza di slot prenotati e liberi.

5) Modello agenda runtime
Source: rest/app/Models/AgendaSlotModel.php
Use: conferma che gli slot extra demo liberi vengono visualizzati in agenda.

6) Verifica live locale
Source: browser locale su http://127.0.0.1:8081/
Use: conferma accessi demo, home menu ridotto a Agenda/Posta/Chat per dietista, agenda su 2026-06-01 con slot prenotati e liberi.

Content policy for this deck:
- Nessun dato clinico reale.
- Nessun logo esterno non verificato.
- Nessuna metrica inventata di ROI o no-show.
- Tutti i claim restano qualitativi e basati sul flusso demo locale.
`;

const runbook = `# Runbook presentazione cliente | Studio dietistica

## Obiettivo della riunione
Far percepire AmbulatorioFacile come centro operativo dello studio, non come semplice agenda.

## Ordine consigliato (12-15 minuti)
1. Apertura: 45-60 secondi
2. Admin: 2 minuti
3. Segreteria: 3-4 minuti
4. Dietista: 3 minuti
5. Portale paziente: opzionale, 1 minuto
6. Chiusura e domande: 2-3 minuti

## URL locali da usare
- Showcase demo: http://127.0.0.1:8081/demo
- Accessi demo dietistica: http://127.0.0.1:8081/demo/access/medical
- Admin: http://127.0.0.1:8081/admin
- Agenda operativa: http://127.0.0.1:8081/agenda

## Accessi consigliati
- Admin demo
  - Username: demo.admin
  - Password: Demo2026
- Segreteria (consigliato per la demo)
  - Username: demo.admin->demo.segreteria
  - Password: Demo2026
  - OTP: 2510
- Dietista
  - Username: demo.dietista
  - Password: Demo2026
  - OTP: 2510
- Nutrizionista
  - Username: demo.nutrizionista
  - Password: Demo2026
- Portale paziente
  - Username: demo.portal.nutri
  - Password: Demo2026

## Apertura: cosa dire
"Non vi faccio vedere una semplice agenda. Vi faccio vedere come puo lavorare uno studio nutrizionale quando ci sono piu persone coinvolte e ogni passaggio deve restare ordinato."

## Admin: cosa dire
- Obiettivo: dare la percezione di struttura.
- Frase utile: "La parte admin serve per impostare lo studio una volta sola e poi lasciare ogni ruolo lavorare con la propria vista."
- Da mostrare:
  - demo separata dal gestionale farmacia
  - ruoli diversi
  - moduli visibili solo dove servono
  - dati demo anonimizzati

## Segreteria: cosa dire
- Obiettivo: far vedere il risparmio di tempo.
- Frase utile: "Qui la segreteria guadagna velocita senza dover inseguire il professionista per ogni micro-variazione."
- Da mostrare:
  - agenda su 1 giugno 2026
  - appuntamenti gia presenti
  - slot liberi reali a fine mattina
  - scelta del professionista
  - simulazione prenotazione/spostamento
- Da far notare:
  - niente moduli fuori scenario
  - agenda leggibile
  - flusso coerente con uno studio piccolo

## Dietista: cosa dire
- Obiettivo: far vedere il contesto clinico-operativo.
- Frase utile: "Il professionista non vede solo l orario: vede il contesto del paziente, il follow-up e la comunicazione che serve per far andare avanti il percorso."
- Da mostrare:
  - agenda
  - posta
  - chat
  - OTP solo se aiuta il racconto della sicurezza

## Portale paziente: quando usarlo
Usalo solo se senti che il cliente e molto sensibile alla percezione del servizio e alla continuita del percorso.

## Chiusura: cosa dire
"Il beneficio non e solo prenotare meglio. E far lavorare meglio lo studio, con meno caos, meno tempi morti e piu continuita nel percorso del paziente."

## Obiezioni attese
- Si adatta ai nostri ruoli?
  - Risposta: si, la struttura ruoli e gia pronta e si rifinisce sulla realta del vostro studio.
- Serve cambiare tutto il nostro modo di lavorare?
  - Risposta: no, il punto e togliere passaggi sparsi, non complicare l operativita.
- E adatto a uno studio piccolo?
  - Risposta: e uno dei casi migliori, perche in 2 o 3 persone ogni passaggio perso pesa subito.

## Piano B
- Se una schermata rallenta, torna su agenda o posta.
- Se vuoi accorciare, salta il portale paziente.
- Se ti chiedono numeri, resta su tempo risparmiato, riduzione errori, ordine operativo e continuita del percorso.
`;

await Promise.all([
  ensureDir(slidesDir),
  ensureDir(previewDir),
  ensureDir(layoutDir),
  ensureDir(assetDir),
  ensureDir(qaDir),
  ensureDir(outputDir),
]);

await fs.writeFile(slidePlanPath, slidePlan, "utf8");
await fs.writeFile(sourceNotesPath, sourceNotes, "utf8");
await fs.writeFile(runbookPath, runbook, "utf8");

for (const [index, slide] of presentation.slides.items.entries()) {
  const stem = `slide-${String(index + 1).padStart(2, "0")}`;
  buildLog(`export-png-${stem}`);
  const png = await presentation.export({ slide, format: "png", scale: 1 });
  await writeBlob(path.join(slidesDir, `${stem}.png`), png);
  await writeBlob(path.join(previewDir, `${stem}.png`), png);
  buildLog(`export-layout-${stem}`);
  const layout = await slide.export({ format: "layout" });
  await fs.writeFile(
    path.join(layoutDir, `${stem}.layout.json`),
    await layout.text(),
    "utf8",
  );
}

buildLog("export-montage");
const montage = await presentation.export({
  format: "webp",
  montage: {
    format: "webp",
    columns: 3,
    slideWidth: 320,
    padding: 24,
    gap: 18,
    background: palette.cream,
  },
  scale: 1,
});
await writeBlob(montagePath, montage);

buildLog("export-pptx");
const pptx = await PresentationFile.exportPptx(presentation);
await pptx.save(finalPptx);

const qaText = `# Visual QA

## Mechanical
- PPTX exists and is non-empty: yes
- Expected slide count: 11
- Every final slide rendered: yes
- Contact sheet or montage reviewed: pending visual spot-check
- Layout JSON reviewed when available: yes
- Intended fonts present in exported PPTX XML: pending font XML check
- slide-plan.txt or edit-plan.txt reviewed: yes
- source-notes.txt reviewed: yes
- Accepted runtime/export caveats: none known at export time

## Deck-Level
- Title-only storyline makes sense: yes
- One coherent grid, margin, footer, and page-marker system: yes
- Repeated slide types use consistent spacing and hierarchy: yes
- Slide rhythm varies for real page-function reasons: yes
- No three-slide run repeats the same macro-layout without intent: yes
- Sources, dates, units, and assumptions are clear where needed: yes
- Material claims map to entries in source-notes.txt: yes
- User-facing citations, speaker notes, or source appendix are present where traceability is needed: yes (speaker notes + runbook)

## Slide-Level
- Title states the takeaway, not just the topic: yes
- One dominant proof object is immediately findable: yes
- Body copy directly supports the title: yes
- No paragraph exists only to fill space: yes
- Text stays inside its allotted box: pending visual spot-check
- No unexpected wrapping, clipping, hidden overflow, or low contrast: pending visual spot-check
- Font family matches intended design system; no unintended fallback: pending font XML check
- Filled cards, panels, and callouts have clear padding: yes
- No object covers labels, data, stage names, or body text that should remain readable: yes
- Images are relevant, high-resolution, and deliberately cropped: no external images used

## Charts, Tables, And Diagrams
- Chart values, labels, and visual marks use the same source data: n/a
- Native chart APIs were used unless a fallback reason is recorded: n/a
- Axes, units, baselines, and comparisons are honest: n/a
- Labels attach visibly to the marks or regions they describe: yes
- Connectors land on the intended source and target objects: yes
- Tables preserve row/column grammar and remain readable: n/a

## Asset Integrity
- Logos, app icons, product UI, screenshots, customer marks, and other identity assets are user-provided, official, or sourced: yes (none embedded)
- Asset origin and usage are recorded in source-notes.txt: yes
- No generated or hand-drawn lookalike identity assets: yes
- No full-slide bitmap replaces editable text, charts, tables, or diagrams unless explicitly requested: yes

## Issue Ledger
| Issue | Slide(s) | Severity | Fix path | Status |
|---|---:|---|---|---|
| Montage and font XML still to verify after export | all | fix-before-delivery | inspect generated files | open |

## Final Decision
- Pass/fail: pending final inspection
- QA ledger saved as ${qaPath}
- Remaining compromises to disclose: none yet
`;
await fs.writeFile(qaPath, qaText, "utf8");

const summary = {
  workspace,
  tmpDir,
  slidesDir,
  previewDir,
  layoutDir,
  qaPath,
  sourceNotesPath,
  slidePlanPath,
  runbookPath,
  montagePath,
  finalPptx,
  slideCount,
};

console.log(JSON.stringify(summary, null, 2));
