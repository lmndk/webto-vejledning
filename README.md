# Webto Vejledning

Kundens vejledning i wp-admin og på sitet. Kun for brugere, der er logget ind (`edit_posts`). Besøgende får intet
(ingen CSS, JS eller panel, og søgeindekset svarer 401). Ren PHP, CSS og lidt vanilla JS. Intet byggetrin.

## Det ser kunden
- **Vejledning** i menuen: oversigt i kategorier og en søgning, der svarer med det samme (ordene står i adressen,
  `?soeg=`). Hver vejledning har sin egen side med **Tilbage** derhen, man kom fra (søgningen, kontrolpanelet, en anden
  vejledning, siden på sitet; ellers oversigten), brødkrumme (Vejledning / emne), **Forrige** / **Næste** i
  oversigtens rækkefølge og en menu til højre med søgning og alle emner (emnet er foldet ud). **Ret vejledningen** gemmer en rettelse på sitet, og **Nulstil til standard** sletter den igen.
  **Skriv vejledning** laver en ny vejledning. Ret og skriv kræver `edit_others_pages`.
- **Vejledning** i adminbaren: på sitet åbner den panelet "Vejledning til denne side" (`<dialog>`) med vejledningerne
  til sidens blokke, indholdstype, side og funktioner, plus søgning. I wp-admin åbner den oversigten med "Til den side,
  du kom fra" øverst.
- **Dækning** (kun Webto: administratorer med en @webto.dk-mail, eller alle på et udviklingssite som homelab - aldrig
  kunden): det samme som `webto-vejledning/status`. Feltet "Vises på" (nøgler) ses også kun af Webto.
  Filteret `webto_vejledning_er_webto` kan ændre svaret (Webto Dashboards "Se som kunde" bruger det).

## Kilder og id'er
| Kilde | Hvor | Id |
|---|---|---|
| Faste | `indhold/*.md` i pluginnet (følger med opdateringer) | `fast/<fil>` |
| Automatiske | hver blok i temaets `blocks/`, egne indholdstyper (`show_in_menu === true`), menuer og header | `blok/<navn>`, `posttype/<navn>`, `site/menuer-og-header` |
| Blokkens tekst | `blocks/<blok>/vejledning.md` i temaet (erstatter den automatiske tekst; feltlisten fra ACF bliver) | `blok/<navn>` |
| Sitets egne | `vejledning/*.md` i temaet (skrevet af AI'en). Med `udvider: <id>` erstatter den en automatisk | `tema/<fil>` |
| Andre | filteret `webto_vejledning_sources` | valgfrit |
| Databasen | rettelser (`_wv_erstatter`) og egne vejledninger fra wp-admin, og `webto-vejledning/gem`, når temaet ikke kan skrives | samme id / `egen/<post-id>` |

Frontmatter: `titel`, `kategori` (navn eller slug), `resume`, `gaelder` (liste), `kilder` (filer relativt til temaet;
en mappe slutter med /), `opdateret` (ÅÅÅÅ-MM-DD TT:MM, sitets tid), valgfrit `udvider` og `raekkefoelge`.

Nøgler i `gaelder`: `blok:<navn>`, `posttype:<navn>`, `side:<id>`, `menu:<plads>`, `plugin:<mappe>`,
`feltgruppe:<nøgle>`, `funktion:<navn>`.

Markdown: overskrifter, afsnit, lister, `> ` tip-boks, tabeller, **fed**, *kursiv*, `kode`, links og billeder.
Særlige adresser: `admin:edit.php?post_type=page`, `vejledning:<id>`, `billede:navn.png` (pluginnets `billeder/`) og
`billede:tema/navn.png` (temaets `vejledning/billeder/`). En linje med `[[farver]]`, `[[skriftstoerrelser]]` eller
`[[afstande]]` viser sitets værdier fra theme.json (filteret `webto_vejledning_pladsholder`).

## Dækning og forældet
- Skal have en skrevet vejledning: hver af temaets blokke, Indlæg/Sider/Medier og egne indholdstyper, ACF-feltgrupper,
  der ikke hører til en blok eller egen indholdstype, menupladser, aktive plugins (ikke `webto-*`) og funktioner fra
  filteret `webto_vejledning_funktioner`. Automatiske vejledninger tæller ikke.
- Forældet: en kilde er ændret efter det seneste af `opdateret`, filens ændringstid og rettelsen i wp-admin.
- Samme svar tre steder: ability `webto-vejledning/status`, `wp webto vejledning status` (fejlkode 1, når noget
  mangler) og siden **Dækning**.

## Abilities (Abilities API, `meta.mcp.public`, så Novamira viser dem for AI'en)
- `webto-vejledning/status`: manglende, forældede, instruktion og skabelon (læs: `edit_posts`).
- `webto-vejledning/hent`: én vejledning (frontmatter + Markdown), eller listen uden id (læs: `edit_posts`).
- `webto-vejledning/gem`: skriver `vejledning/<navn>.md` eller `blocks/<blok>/vejledning.md` i temaet. Kan WordPress
  ikke skrive i temaet, gemmes den i databasen med samme virkning (`manage_options`). På homelab kan WordPress skrive
  i projektmappens tema (se `skabelon/compose.yaml`: gruppe 1000, setgid, umask 0002).

## Webto Dashboard
Når Webto Dashboard er aktivt, lægger vejledningen sit kort i kontrolpanelet via `webto_dashboard_tiles`
(`includes/dashboard.php` + `assets/kort.css`): søgning, "Ny her? Start her" og sitets senest opdaterede vejledninger.
Desuden genvejen **Vejledning** i rækken under toppen (`webto_dashboard_genveje`, rækkefølge 60).

## Udvidelsespunkter
`webto_vejledning_sources`, `webto_vejledning_kategorier`, `webto_vejledning_funktioner`, `webto_vejledning_krav`,
`webto_vejledning_side_funktioner`, `webto_vejledning_side_noegler`, `webto_vejledning_skaerm_noegler`,
`webto_vejledning_blok_plugins`, `webto_vejledning_site_blocks`, `webto_vejledning_site_post_types`,
`webto_vejledning_pladsholder`, `webto_vejledning_cap_read`, `webto_vejledning_cap_edit`.

## Filer
`webto-vejledning.php` (indgang, rettigheder, den skjulte indholdstype `webto_vejledning`) og `includes/`:
`markdown.php`, `kilder.php`, `pladsholdere.php`, `status.php`, `admin.php`, `adminbar.php`, `rest.php`,
`abilities.php`, `cli.php`. `assets/`: `vejledning.css` (wp-admin), `panel.css` + `panel.js` (sitet), `soeg.js` (begge).

## Test
`hb/tests/t-vejledning.js` i skabelonens repo (47 tjek: søgning, rettelse/nulstil, status via ability og WP-CLI, gem,
forældet, panelet med fokus/Esc/mobil, axe, besøgende). Testen rydder selv op. `hb/tests/t-vejledning-nav.js` (22 tjek):
Tilbage fra søgning, oversigt, kontrolpanel, anden vejledning og sitet, brødkrumme, Forrige/Næste, menuen, mobil.

## Opdateringer
Fra GitHub via `includes/opdatering.php` (automatisk; kun udgivelsens zip-fil). Kildekoden i skabelonen har markørfilen
`.udvikling` og opdateres aldrig. Udgivelse: `plugins/udgiv.sh webto-vejledning <version>` (se `plugins/README.md`).
