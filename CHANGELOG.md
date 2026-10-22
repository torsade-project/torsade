<!-- torsade/CHANGELOG.md -->

# Changelog

All notable changes to Torsade are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.20.0] - 2026-10-22

Upgrading from 0.19.0 runs no migration and asks nothing of the administrator: the application does not change. This is the first public version of Torsade.

### Added

- Torsade is free software, under the GNU General Public License, version 3 or any later version (`GPL-3.0-or-later`). The text of the licence is in `LICENSE`, and the licence is declared in `README.md`, `composer.json` and `package.json`; a contribution is received under the same licence, without any assignment of rights. The source code of each published version is in the repository, at the tag of the version.
- `SECURITY.md`, in French then in English, tells how to report a vulnerability: privately, through the private vulnerability reporting of GitHub, never in a public issue, discussion or pull request. While the version is `0.y.z`, only the latest version receives security fixes. `README.md` and the table of contents of the documentation link to it.
- The archive of a release ships `LICENSE` and `SECURITY.md`.
- On a tag, the CI attests the provenance of the archive with an artifact attestation of GitHub, in a job of its own that cannot write to the content of the repository, and verifies that attestation before it creates the release. An administrator may verify it as well, from a computer where `gh` is signed in to a GitHub account: `gh attestation verify torsade-X.Y.Z.tar.gz --repo torsade-project/torsade`. The pages of the installation, the update and the security describe it, and give the address of the releases, `https://github.com/torsade-project/torsade/releases`.
- The troubleshooting explains an error 400 on every page, "La requête a été refusée": the application was opened under another name than its own, or the address of a machine installed by its IPv4 address changed. The installation says that the IPv4 address of a trial must stay fixed, by a reservation on the DHCP server or a static address.

### Changed

- The repository is now `torsade-project/torsade`; the guide of development clones it from there.
- `README.md` opens on a summary in English, says that 0.20.0 is the first public version, and links to the security policy and to the conventions of a contribution.

### Security

- The tools of development move to vite-plus 1.3.0, which no longer bundles the vulnerable versions of launch-editor (GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm), and vue-tsc to 3.3 with it. They are tools of development only: none of them is in the archive of a release.

## [0.19.0] - 2026-10-19

Upgrading from 0.18.0 keeps every object and runs three migrations, without losing anything: a new table holds the jack cords; a position placed by hand can also be the one of a patch panel, once per panel and on the physical diagram only; the length of the blocks of sites of the addressing rule becomes optional, the rule in place keeping its own. Going back to 0.18.0 loses only the jack cords, the positions of the panels and a rule without a level of sites.

### Added

- Jack cords: a port of a device is plugged into a wall jack of its site, on the side of the desk, from the line of the port ("Brancher sur une prise") or from the line of the jack ("Brancher un équipement"), with a colour and a description. From the list of jacks a cord is changed or unplugged; from the page of a device a port is unplugged, or every port at once with "Débrancher tous ses ports". A jack has one jack cord and a port one connector, a patch cord or a jack cord. A retired jack or device, and a jack and a device of two sites, are refused; a jack, a port, a member or a device plugged in is not deleted, and a device plugged in does not move to another site. Each cord laid, changed or taken away is in the activity log.
- The path of a jack begins with the device plugged into it ("Branché : ap-etage-1 eth0 › B.13 › …"), and the ports of a device say which jack they are plugged into, leading to it.
- Cabled paths: a port patched to a jack into which another port is plugged is cabled to that port, worked out at each display and never stored. When a link joins the two ports, its physical path is shown under its medium in the list of links ("par PB-B 13 › B.13").
- Four deviations of the cabling: two ports cabled without a link, with "Créer la liaison", which opens the window of the link with both ports chosen; a link the cabling contradicts; the cabling of a retired link, still in place; a port linked and patched to a jack where nothing is plugged, with "Brancher", which plugs the port in front into that jack.
- The physical diagram draws the cabling. A connection that has a cabled path, a link, a cabling without a link or a device cabled by its address, goes in two segments through the port of its patch panel, the jack naming the second one; the panels it goes through are drawn in the frame of their room, with the ports used only, each leading to its page. Every device a cabled path joins to a device drawn is drawn, step by step, a device of another site plugged into a jack of the site at the edge. A link the cabling contradicts keeps its direct line, its cabling drawn beside it; an aggregated link stays one line and tells the cabled path of each of its links. The end devices are drawn smaller and counted apart in the summary, and the legend has the panel, the jack and the end device.
- "Afficher les postes" hides the workstations, laptops, printers, telephones and cameras of the physical diagram, with their connections and the panels that only served them; each device they are joined to tells how many. It and "Regrouper par lieu" are kept by the browser, for every site. While the devices are placed by hand, the end devices are always shown.
- The patch panels are placed by hand on the physical diagram, as the devices are; the activity log counts the elements, the devices and the panels placed.
- An addressing rule may have no level of sites, "Aucun (un seul site)": the subnets of VLANs are then ranked by VID straight in the block of the organisation, `192.168.20.0/24` for the VLAN 20 with `192.168.0.0/16` and /24 subnets, the same on every site. Such a rule uses no number of a site and expects no block of a site, which the page of the rule says.
- The demonstration plugs access points and the switch of a meeting room into jacks, cables a printer by its address, keeps a cabling without a link, a contradicted link, the cabling of a retired link and a port linked without a plug, and places the panel of the workshop on its diagram.
- The guide describes the jack cords, the cabled paths and their deviations, the cabling on the physical diagram, its settings and the end devices, and the rule without a level of sites.

### Changed

- The form of a block and the section "Blocs d'adressage" of every site explain that the block of a site is the envelope of all its subnets, that the network of a LAN is the subnet of its VLAN rather than a block, and that a site without a hierarchical plan may have no block.
- "Niveau de site" replaces "Longueur des blocs de site" in the form of the addressing rule, which offers the lengths the block leaves, and in the activity log.
- The diagrams are saved as SVG files in the light theme, as they are printed, whatever the theme on screen.
- The page of results of the search moves to `/search?q=…`, and the search of the window to `/search/results`; the former addresses, `/recherche` and `/recherche/resultats`, redirect to them for good, query included. Escape closes the window of the search and keeps its text, as "Fermer" does.
- The window that patches a port of a panel offers the ports linked to another device towards a jack as towards a riser panel, and no port plugged into a jack. The deviation of a port linked and patched to a jack gives way to the deviations of the cabled paths.
- On the page of a device, "Débrasser tous ses ports" is shown only when a port is patched.

### Fixed

- A patch cord laid on a device that another user moves to another site at the same moment is refused, instead of joining two sites.

## [0.18.0] - 2026-10-18

Upgrading from 0.17.0 keeps every object: no migration. Only the documentation changes.

### Added

- The guide of the users, in `documentation/guide/`, organised by task, each task with the role it needs: first steps, the notions of Torsade, sites and places, the standard, VLANs, subnets and addresses, devices and ports, links, cabling, diagrams, every deviation with its reason and how to resolve it, accounts and the activity log, and a glossary. Its examples come from the demonstration network.
- The guide of development, `documentation/developpement.md`: requirements, local installation, commands, tests, demonstration, content security policy, archive of a release, and the conventions of a contribution.
- The table of contents of the documentation, `documentation/README.md`, by audience: users, administration, contributors.
- `npm run docs:check` checks that every relative link of the documentation leads to a file and a heading, that every page is in the table of contents, and that every label of the interface quoted in the guide is one of the application; the CI runs it, after trying both checks on good and bad examples.

### Changed

- `README.md` is rewritten as the entrance to the documentation: what Torsade is and is not, its state, its features, and the links to the installation, the guide, the development and the changelog.

## [0.17.0] - 2026-10-18

Upgrading from 0.16.0 keeps every object: no migration.

### Added

- One search for the whole documentation, from the field "Rechercher" in the header of every page, also opened by `/` or Ctrl+K outside of a field, and from the home page. What is typed is read first as an IPv4 address, a CIDR prefix (brought back to its network address, and said so), the beginning of an address (`10.20.`), a VLAN number, a MAC address in any of its four notations or the beginning of one, or a device followed by one of its ports (`sw-etage-1 1/0/24`); otherwise it is looked for as text in the names and descriptions of sites, places, VLANs, the VLAN catalogue, blocks, subnets, addresses, devices, ports, jacks, patch panels and templates, without regard to case or accents, `%`, `_` and `\` looked for as they are. Accounts and the activity log are never searched. The window shows the results as the text is typed, once the typing pauses, five per group with the number of each group, the exact matches first and the retired objects last, the part that matches in bold and underlined; the arrows go through them, Enter opens the one chosen, or the page of results while the results of what was just typed have not come yet, Escape closes the window. A result opens the page of its object, or the page that shows it at its line: an address on the page of its subnet, a port on the page of its device, a place on the page of its site, a jack in the list of jacks, a template in the list of templates.
- An address that no record holds is said not to be documented, with the subnet that holds it; an editor or an administrator can document it from there, the window of a new address of that subnet opening filled in with it.
- The page of results of a search, whose address can be shared (`/recherche?q=…`): every group, 25 results each, and "Afficher plus" under a group. Each user has 120 searches a minute.
- The home page is made of modules: the search field; the first steps of a documentation, for editors and administrators as long as a site, a VLAN, a subnet or a device is missing, and for readers on an installation without any site; the summary of what is documented, each number leading to its list and saying how many are planned; the sites, with their status, what they hold and their two diagrams; the fullest subnets; and, for administrators, the recent activity of the log.
- The guide describes the home page and the search, in `documentation/guide/accueil-et-recherche.md`.

### Changed

- Each line of "Appliquer la norme" shows the ranges the standard breakdown would create in its subnet, their kind and their first and last addresses, worked out again as the subnet is corrected. The box "Découpage type" decides whether they are created; when the rule has no breakdown, or when it does not fit in the subnet, the line says so and the box is inactive.
- From the page of a patch panel, a port is cabled to a jack without a horizontal cable of any floor of the site, offered by building and floor, and no longer only of the floor of the panel.
- Creating devices from host names compares the names as the names of devices are compared, without regard to case or accents: a device named "imp-étage-2" takes the addresses named "imp-etage-2".
- The window that confirms the deletion of a room says that devices, members of devices or patch panels prevent it, and the refusal names, at once, each of those the room still holds.
- The help of the code of a site says where Torsade uses it: in the summary of a port and in the name of an exported diagram.
- The documentation of security gives the room a Nginx placed in front of Torsade needs for the headers of the pages, and names the guide of the ANSSI on TLS by its title and edition. The documentation of security and that of the installation count the restoration among the uses of the account of the migrations.

### Fixed

- `torsade-update` and `torsade-restore --release` wait for Nginx to have read its configuration again before checking `/up`, 20 seconds at most; past that, a warning goes to the journal and the check takes place all the same.

### Security

- An administrator can no longer reset their own password from the list of users: the button is gone from their line and the server refuses the request. Their own password is changed in their settings.

## [0.16.0] - 2026-10-17

Upgrading from 0.15.0 keeps every object: no migration. In production the session cookie is now named `__Host-torsade-session`, so everyone signs in again once.

### Added

- Torsade installs on a dedicated Debian 13 machine with `sudo bash deploy/install.sh`, from the archive of a release, without Composer nor Node on the server: Nginx, MariaDB and PHP-FPM from the repositories of Debian, a system account `torsade` without shell that runs every command of the application, the code in `/opt/torsade/releases`, the configuration in `/etc/torsade`, the data in `/var/lib/torsade`, the logs in `/var/log/torsade`, a database that listens on the machine only, reached by an account that reads and writes the data and nothing else, TLS 1.2 and 1.3 with a certificate provided and checked or a self-signed one for a trial, HSTS on an explicit option only, the security updates of Debian on another, and the first administrator. The script asks its questions or runs without any, names the step that fails, resumes where it stopped, and refuses to run over a complete installation.
- `sudo torsade-update torsade-X.Y.Z.tar.gz` updates an installation: it checks the digest of the archive, refuses a version that is not newer, puts the application in maintenance and backs it up, then migrates and switches to the new version at once, keeping the last three. A failure leaves the previous version served, or names the command that brings it back with its data; until then, backups and updates wait.
- `torsade-backup` exports the database and the settings to keep, the key of the application among them, each night, kept 14 days; `torsade-restore` puts a backup back, on the same machine or on a new installation.
- Each release on GitHub carries the archive `torsade-X.Y.Z.tar.gz` and its `SHA256SUMS`.
- `php artisan torsade:check` checks the configuration of a production installation, one line per check with what to correct, and ends with a non-zero code when any check fails.
- `php artisan torsade:purge-health-cache` removes the expired entries of the cache of `/up`; a timer of the system runs it each day.
- The documentation of administration, in `documentation/administration/`: installation, trial in a virtual machine, update, backup and restoration, security, daily running.

### Security

- Every page, error pages included, carries a Content-Security-Policy that only allows the scripts and styles of the application, marked with a nonce drawn at each request, and `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy` set to `same-origin`.
- HSTS can be turned on with `HSTS_ENABLED`: for a year, on HTTPS requests only, without subdomains.
- In production the session cookie is `Secure` and its name prefixed with `__Host-`, and the remember-me and `XSRF-TOKEN` cookies are `Secure`.
- In production a request whose host is neither exactly that of `APP_URL` nor one of `TRUSTED_HOSTS` receives an error without detail, and the `X-Forwarded-*` headers are only believed from the proxies declared in `TRUSTED_PROXIES`.

## [0.15.0] - 2026-10-15

Upgrading from 0.14.0 keeps every object, and every position placed by hand, as the position of a device. The migration lets a position also be the one of a subnet or of the internet, on the logical diagram; the database keeps one place per device, per subnet and for the internet on each diagram.

### Added

- The logical diagram of each site, from "Schéma logique" in the group "Réseau logique" of the menu, or from the page of a site, the site in the address of the page. It draws the subnets of the site, of every kind, and the subnets without a site where a device of the site drawn has an address, as bars with their address, their name, their VLAN, their gateway and the number of addresses that belong to no device drawn ("37 autres adresses"); the internet, linked to each external subnet; every firewall, router and switch of the site, even without an address or a VLAN, any other device of the site that has addresses in two subnets drawn or carries the VLAN of one, and, at the edge with the name of their site, the devices of other sites that have an address in a subnet drawn or carry its VLAN. Each device is plugged once into each subnet where it has an address or whose VLAN it carries, its addresses written near it, the gateway tagged; the tooltip of a connection tells how the VLAN is carried ("Accès sur 24 ports, étiqueté sur 2 ports"). It reads from the internet down: the external, tunnel, point-to-point and loopback subnets, the firewalls and the routers, the segments, then the switches and the other devices. Like the physical diagram, it has its legend and its summary, moves and zooms, downloads as SVG, prints, tells its deviations, those of the subnets, of their VLANs and of the devices, on the drawing, from the keyboard and in "Écarts du schéma", and an editor or an administrator places its bars, the internet and its devices by hand; each save, and each return to the automatic layout, is an entry of the activity log on the site that names the diagram and gives the number of elements placed.
- The deviation of an aggregate declared on one side only offers "Créer l'agrégat en face" to an editor or an administrator, when the ports in front are all on one device and in no aggregate: on the page of each of the two devices, on the page of the links and in "Écarts du schéma". It opens the window of a new aggregate of the device in front, filled in with those ports, the protocol, the mode and the VLANs of the aggregate in front, and a name in the series of the device, with a reminder that the ports ticked lose their own mode and their own VLANs. Once saved, the deviation is gone and the links are one aggregated link.

### Fixed

- A diagram is measured with its fonts loaded: shown for the first time, it no longer measures a text in a weight the page had not used yet with another font, and the same data always gives the same layout.

## [0.14.0] - 2026-10-15

Upgrading from 0.13.0 keeps every object. The migration adds the table of the positions of the devices placed by hand on a diagram, empty: every diagram stays laid out on its own until a layout is saved.

### Added

- The devices of the physical diagram of a site can be placed by hand, by an editor or an administrator. "Modifier la disposition" shows a grid of 8 pixels behind the diagram and a bar above it: a device is moved with the mouse or a finger, or from the keyboard with the arrows, by 8 pixels, or by 40 with Shift, and its links follow it. "Enregistrer" saves the position of every device drawn, all at once; it is refused, in words, when the layout was changed in the meantime, and "Annuler" goes back to the layout saved. "Réorganiser automatiquement", after a confirmation, deletes the positions, and the diagram is laid out on its own again. Leaving the page with moves not saved asks first. Once a layout is saved, every device keeps its place, the frames of the places surround the devices they hold, the links are drawn in simple right angles, and a device added later lines up on a row below the others, marked "Non placé"; the file saved and the page printed show the layout drawn. Each save, and each return to the automatic layout, is an entry of the activity log on the site, with the number of devices.
- "Écarts du schéma", under the physical diagram, lists each deviation it draws, with its reason and a link: to the page of the device, or to the links of the site that differ. Each warning of the diagram is reached from the keyboard, after the devices: its reasons open in a tooltip when it has the focus, when it is touched or on Enter, and close with Escape or a touch elsewhere.

### Changed

- The menu folds: home and the sites stay at the top, and each group, "Norme", "Réseau logique", "Réseau physique" and "Administration", opens and closes from its title, a button with a chevron, by a click, Enter or Space. The group of the page shown is open; what each user opens or closes is kept by their browser from one page and one visit to the next, and without storage in the browser the menu works with the group of the page open. Reduced to its icons, the menu shows every entry flat, a line between its groups. The sidebar is wider, so that no entry is cut off, and its scroll bar is thin, in the colours of the theme, and only there when the menu is taller than the window.
- Composer now refuses to install Torsade on PHP older than 8.4.1, the oldest version its dependencies accept.
- `torsade:demo` places the physical diagram of the workshop by hand, but for the access point of the quay, which shows the row of the devices not placed; the head office keeps the automatic layout.

### Fixed

- A browser that refuses its storage, in a private window or with the data of the site blocked, no longer leaves the pages blank: the theme is then read from its cookie.

## [0.13.0] - 2026-10-15

Upgrading from 0.12.0 needs no migration.

### Added

- The physical diagram of a site, worked out from what is declared and laid out in the browser: the firewalls, the routers, the switches and the access points of the site, any other device of the site that has a link, and, at the edge, the devices of other sites linked to them; each device in the frame of its room, its floor and its building, or in "Sans local". Only the ports that are linked are drawn, the links between two aggregates as one, read from top to bottom, from the firewalls down; a planned device or link is dashed, a fibre thicker, and a deviation of a link or of a device is shown in orange with a warning that tells its reason. The diagram is moved by dragging its background and zoomed with its buttons, Ctrl and the wheel, a pinch, or + and − once it has the focus; "Ajuster" brings all of it back in sight, and "Regrouper par lieu", unticked, lays it out without its frames. Each device is a link to its page, in the order of reading, and a summary tells how many devices, links and deviations it shows, and how many devices of the site it leaves out. It is reached from the menu, "Schéma physique" in "Réseau physique", where a list chooses the site, which stays in the address of the page, and from the page of a site. The layout engine, ELK, runs in a worker served by the application, with its licence shipped in the build.
- "Télécharger en SVG" saves the whole physical diagram, whatever part of it is in sight, as an SVG file named after the site and the day, "schema-physique-SIE-2026-10-15.svg": the colours of the current theme are written in as values and each text falls back on a generic font, so that the file opens anywhere. "Imprimer" prints the title, the legend and the whole diagram fitted to one sheet, in the light theme whatever the one on screen.
- "Débrasser tous ses ports", in the section of the ports of a device, takes away every cord of the device at once, for an editor or an administrator. A confirmation names the cords by their two ends, ten at most, then how many more; each cord is an entry "Port débrassé" of the activity log, a message tells how many were taken away, and an unpatching the database gives up for a crossing write is replayed. The action is unavailable for a device without a cord.

### Changed

- A device one of whose ports is patched no longer moves to another site, a cord never joining two sites: the refusal names its cords, ten at most, then how many more. Its links, which may join two sites, do not hold it.

### Security

- Symfony HTTP Foundation and Symfony YAML are updated to 8.1.9, past two published flaws: a crafted `Cache-Control` request header, which anyone could send without logging in, could keep the server busy for a long time (CVE-2026-108082), and a crafted YAML document could crash the process that reads it (CVE-2026-109413). The PHP version the dependencies are resolved for is now 8.4.1, the oldest these updates accept.

## [0.12.0] - 2026-10-14

Upgrading from 0.11.0 needs no migration.

### Added

- A port or an aggregate carries VLANs of any site: a central firewall that routes the VLANs of several sites, a fibre that carries those of another building. The windows of a port, of an aggregate and "Mode et VLAN" for several ports offer the VLANs of the site of the device first, then those of the other sites, each under its name. Among the tagged VLANs, the group of another site stays folded until it is opened, unless the port already carries one of its VLANs; "Tous" ticks the VLANs of the site of the device, and the one of a group those of its site. Two VLANs of one number are refused on one port or one aggregate, with both named by their site: "Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port."
- The number of a VLAN no longer changes when a port or an aggregate that carries it already carries another VLAN of the new number: the refusal names them, device by device, ten at most, then how many more.
- A link whose two ends carry two different VLANs under one number, however each end carries it (untagged, voice, native or tagged), is a deviation, one for each number: "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux". It is shown where the other deviations of a link are: on the list of links, on the links of each device and in the list of the deviations of its page. As for the other deviations of configuration, both ends need a mode; the same VLAN of another site at both ends is no deviation.
- A switch whose ports and aggregates carry, together, two different VLANs under one number merges them, having one table of VLANs: it is a deviation, one for each number, at the top of the list of the deviations of its page, "VLAN 10 des sites Siège et Atelier : sw-coeur-02 les fusionne". A firewall or a router, which keeps them apart, is not concerned.

### Changed

- The menu follows the order in which a network is documented, in named groups: "Accueil" and "Sites", then "Norme" (address blocks, addressing rule, VLAN catalogue), "Réseau logique" (VLANs, subnets), "Réseau physique" (devices, links, jacks, device templates) and "Administration" (users, activity log). The menu reduced to its icons hides the titles of the groups and keeps the order, each entry keeping its name for a screen reader. Each entry is shown to the same roles as before, and a group without an entry the user may open is not shown: no "Administration" for a reader or an editor.
- What a port or an aggregate carries, summed up in a few words, names the site of a VLAN of another site than the one of the device: the VLANs of the site of the device come first, then those of each other site behind the code of that site, or its name when it has no code, "Trunk, natif 99 : 10, 20 · ATL 60, 70". The table of ports, the aggregates, the window that links a port, the path of a jack and the page of a panel show it that way.
- An entry of the activity log that lists the VLANs of a port or of an aggregate names a VLAN of another site with the name of its site, after the VLANs of the site of the device: "U 99 ; T 10, 20 · Atelier 60, 70". The entries already written stay as they are.
- A device whose ports or aggregates carry VLANs changes site, and so does a VLAN that ports carry: what the ports carry stays as it is, and the VLANs that are then of another site than the device are named with their site.
- An address attached to a device of another site than its subnet is no longer a deviation when the device carries the VLAN of the subnet on one of its ports or aggregates, in any mode: the device is plugged into that network, as a central firewall is into the networks of the sites it routes. A subnet without a VLAN keeps the rule of the site.
- The deviation of an address whose VLAN the port at the end of its jack does not carry compares the VLAN itself, no longer its number: a port on the VLAN 10 of another site does not carry the VLAN 10 of the address, and the reason then names both with their site, "fw-siege 1/0/2 en accès VLAN 10 du site Atelier, adresse 10.10.10.5 du VLAN 10 du site Siège".
- The demonstration network has a planned fibre between the head office and the workshop, from sw-coeur-02 to sw-atelier-01. The VLAN 60 of the workshop at both ends, carried by a switch of the head office, is no deviation; the VLAN 10 of each site, one at each end, is the deviation of a link that merges two networks, explained in the description of the link.

## [0.11.0] - 2026-10-14

Upgrading from 0.10.0 needs no migration.

### Added

- The colour and the description of a cord are changed in a window, from the line of its port on the page of its panel. The window shows the two ends of the cord, which do not change, and says that a cord is moved to another port by unpatching the port and patching it again. A cord unpatched in the meantime is told in words. Each change is an entry of the activity log, "Cordon modifié", which names the two ends, with the values before and after.
- "Unpatch", among the actions on the ports ticked of a panel, takes away the cords of the ports ticked at once, consecutive or not: the ports without a cord are left out, and the action is unavailable when none has one. A confirmation tells how many cords go and names them by their two ends, ten at most, then how many more; once they are taken away, the ticks are cleared. Each cord is an entry of the activity log, and an unpatching the database gives up for a crossing write is replayed, as a patching is.

### Changed

- The deviation of a cord between two rooms names each room without what the two have in common: "Panneau dans Local technique, sw-atelier-01 dans Bureaux" on one floor, the floor added when the floors differ, the building when the buildings do.
- An address whose VLAN the port at the end of its jack does not carry is a deviation when it is active or planned, no longer when it is retired. An address on a jack that is not patched is still a deviation for an active address only.
- The demonstration network gives each floor of the head office a technical room, a cupboard of the corridor with its wall rack, which keeps the switch of the floor and its patch panel. The rescue cord from the technical room of the ground floor to the switch of the first floor stays a deviation between two rooms, and a camera planned on a jack whose port does not carry the VLAN of the cameras shows the deviation of an address that is only planned, explained in its description.

## [0.10.0] - 2026-10-14

Upgrading from 0.9.0 keeps every object. The migrations add the tables of the patch panels, of their ports, of the wall jacks and of the patch cords, all empty, and to the IP addresses a column for the jack each one is plugged into, empty as well: no existing value changes.

### Added

- Patch panels, kept in a room: a name unique within the room, a number of ports from 1 to 96, a connector, RJ45, LC fibre, SC fibre or other, an optional category, a status and a description. On the page of a site, each room lists its panels, with how many of their ports are occupied, and creates, changes and deletes them in a window. The ports of a panel are numbered from 1 and made with it; more ports adds some after the last one, fewer takes the last ones away, unless one of them has a jack or a cord, and a panel is not deleted while one of its ports has one, nor its room while it holds a panel. The activity log tells a panel and its ports in one entry.
- The page of a patch panel draws its ports as on the panel itself, twenty-four per line, each telling by its shape whether it is free, has a jack without a cord, is patched, or has a cord without a jack, with a legend and a summary in words for screen readers; a table lists each port with its jack and where the jack is, its cord, the port of the device the cord goes to and what that port carries.
- Wall jacks, on a floor: a label unique within the floor, "B.15", an optional location, a status, a description, and the port of a patch panel of the same site where its horizontal cable ends, which has one jack at most. On the page of a site, each floor tells how many jacks it has and adds them one by one, or as a series: a start and an end of the label, numbers from a first to a last one written with at least so many digits, and optionally a panel and the port the series is cabled from, in order. The window shows the labels and their ports while the series is typed, and a series of up to 200 jacks is created whole or not at all: a label already taken on the floor, a port of the panel that already has a jack and a series that goes past the last port of the panel are named in the refusal. The activity log tells a series in one entry, on its floor, with each label and its port.
- On the page of a patch panel, a port without a jack is cabled to a jack of the floor of the panel that is not cabled yet, and a jack is detached from its port: it stays on its floor and the port is free again. A floor is not deleted while it has jacks, nor a jack while an address is plugged into it.
- "Jacks", reached from the menu: the list of every jack, by site, building, floor and label, B.2 before B.10, each with where it is, its location, its path, its addresses and its status. The path goes from the jack to the port of a panel its horizontal cable ends on, to the device and the port the cord of that port goes to, and to what that port carries, "PB-A 15 › sw-etage-1 1/0/15 › Accès 10", and says where it stops: not cabled to a panel, or not patched. The list is filtered by site, building, floor and status, and searched by label and location; a jack is modified and deleted from there as well.
- Patch cords. On the page of a patch panel, "Patch…" on the line of a port lays a cord to a port of a device of the site: the window lists the devices of the site, then the ports of the one chosen that have no cord yet, and a port linked to another device is offered only towards a port of the panel without a jack, as on a riser panel. The ports ticked that follow one another are patched as a series to as many ports of a device, in the order of the device, member then place, from a first port, "ports 1 to 24 of the panel on 1/0/1 to 1/0/24": the window shows which port goes with which, the cords are all laid or none is, and the ticks are cleared once they are. A cord has an optional colour and a description. A port of the panel or of the device that is already patched, and a device of another site, are refused, with a message that names what is in the way; a patching the database gives up for a crossing write is replayed, as a link is. A cord is taken away after a confirmation, and its two ports stay. The activity log tells each cord laid or taken away, one entry per cord of a series.
- The page of a device tells, on the line of each patched port, the jack the port is patched to, "brassé vers B.15", or the port of the panel when that port has no jack, with a link to the page of the panel.
- An IP address can name the wall jack its machine is plugged into: the window of an address has a field "Jack" that looks for a jack by its label among those of the site of the subnet, or of every site for a subnet without one; a jack of another site is refused. The table of the addresses of a subnet and the page of a device show the jack of each address with its path, under its host name. Plugging an address into a jack and unplugging it are entries of the activity log of their own, "Adresse raccordée à une prise" and "Adresse débranchée d'une prise", which its filters offer.
- Deviations of the cabling, worked out when a page is shown, never stored and never refusing anything, each with its reason in words: a cord between a panel and a device kept in two different rooms, the room of the member of the port counting before the one of its device; a port linked to another device and patched to a wall jack; an active address plugged into a jack that is not patched, without a horizontal cable or without a cord; an address whose VLAN the port at the end of its jack does not carry, in access as its VLAN or its voice VLAN, in trunk as its native or a tagged VLAN, those of its aggregate for a port of an aggregate, and a port without a mode not compared. They are shown on the lines of the page of a panel, in the list of jacks, which keeps the jacks with a deviation on demand, on the addresses of a subnet and of a device, on the line of each port of a device and in the list of the deviations of its page, and on the line of a link.
- The demonstration network gains its cabling: a patch panel in each technical room, a fibre panel without jacks at the head office patched to the uplinks of the core switches, and a panel in the cupboard of each floor of the head office, next to its switch; wall jacks labelled as series on the floors and cabled to the panels; cords to the switches of the floors and of the workshop; workstations and telephones plugged into the jacks. Some jacks are cabled and not patched, some ports of the panels are free, and three deviations of the cabling are there on purpose, each explained in a description: an active address on a jack that is not patched, an address whose VLAN the port does not carry, and a cord between two rooms.
- A patched port cannot be deleted, nor can its member or its device: the refusal names the cord in the way, "1/0/15 est brassé vers PB-A 15 (B.15)", and the port is to be unpatched first.

### Fixed

- Two people who link the same ports at the same moment no longer get a server error: a link the database gives up because another write crossed it is written again, and if it is given up every time, the window says so and asks to try again.
- The ports ticked to be linked as a series are no longer ticked once their links are saved.

## [0.9.0] - 2026-10-13

Upgrading from 0.8.0 keeps every object. The migrations add the tables of the links between devices and of their ends, both empty: no existing table changes, and no port is linked until a link is declared.

### Added

- Links between devices: a link joins a port of a device to a port of another device, of the same site or of another one, and a port has one link at most. A link says what is plugged into what, whatever the cables and the patch panels in between. It has a status, an optional medium, copper, multimode fibre, single-mode fibre, direct-attach cable or other, and a description.
- "Link…", on the line of a port of the page of a device, opens a window that looks for the opposite device by its name, on every site, lists the ports of that device no link uses yet, each with its mode and its VLANs, and asks for the medium, the status and the description of the link. "Link as a series" does the same for the ports ticked in the table: as many free ports of the opposite device are chosen, the ports are linked in the order of each device, and the window shows which port goes with which before anything is saved. The links of a series are all created, or none is.
- A port that is already linked, on either side, two ports of the same device, a port deleted in the meantime and a different number of ports on each side are refused, with a message on the field concerned that names what is in the way.
- The medium, the status and the description of a link are changed in a window, and a link is deleted after a confirmation: its two ports stay, free to be linked again. Each link created, changed or deleted is an entry of the activity log, named by its two ends.
- A linked port cannot be deleted, nor can its member or its device: the refusal names the link in the way, "1/1/1 est relié à sw-coeur-01 › 1/0/49", ten of them at most for a member or a device, and the link is to be deleted first.
- The page of a device shows, in the table of its ports, the device and the port each linked port faces, with a link to that device, and has a section "Links" between its aggregates and its ports: one line per link, with its port, the opposite device and port, its medium, its status and its description. The links between the ports of two aggregates are one aggregated link, shown as one line that opens on the links it is made of.
- "Links", reached from the menu: the list of every link, with its two ends, each with its device and its site, its medium, its status and its deviations, an aggregated link as one line. The list is filtered by site, which keeps the links one of whose ends is on that site, by status, and down to the links with a deviation; a link is modified and deleted from there as well.
- Deviations between the two ends of a link, worked out when a page is shown, never stored and never refusing anything, each with its reason in words: an access port facing a trunk, two access ports on different VLANs, two trunks whose native VLAN differs or is missing on one side, two trunks that do not tag the same VLANs, an aggregate whose links lead to several devices, an aggregate facing ports that are not one same aggregate, and an active link on a port that is disabled. What is compared is the mode and the VLANs of each port or, for a port of an aggregate, those of its aggregate, by the numbers of the VLANs, so that a link between two sites is compared too; an end without a mode, the port of a firewall or of a server, is not compared. They are shown on the line of the link, on the page of both devices and in the list of links, on the aggregate, and in the list of the deviations of the page of the device.

- The demonstration network gains its links. Its two core switches and a switch of the workshop get their member and their ports from the generic templates; the firewall is linked to both core switches, the core switches to each other, the stack to the first of them by an aggregated link made of a link from each of its two members, and the switches of the floors and of the quay by their uplink. Two links differ at their two ends on purpose, a VLAN tagged on one side only and two different native VLANs, and say so in their description; one link is planned.

### Changed

- The activity log tells ports created or deleted in number in one entry, with their number and their names: the ports a member gets from its template, a series of ports added to a member, and the ports deleted with their member or with their device are written on the member or on the device. Every change of a port is still an entry of its own, so that each port keeps its history. The entries written before stay as they are.
- In the table of the ports of a device, the column "Aggregate" is gone. A port of an aggregate shows, in the column of the mode and of the VLANs, what the aggregate carries: "via Po1 : Trunk, natif 99 : 10, 20".
- "Add ports" accepts a pattern without `{n}` when it adds one port alone, its first and its last number being the same: "MGMT", "WAN". A series of several ports still needs `{n}`, and the refusal says so.
- When a device that has a serial number gets its first member, by "Choose a template" or by "Add ports", the number goes to the member 1 and leaves the device: it only exists in one place. Both windows say so before anything is saved, and both changes are written to the activity log.

## [0.8.0] - 2026-10-12

Upgrading from 0.7.0 keeps every object. The migrations add the tables of device templates, of the members of devices, of their ports, of the aggregates of ports and of the VLANs ports carry, all empty: no existing table changes, and a device has no member nor port until one is declared.

### Added

- Device templates, reached from the menu: what a kind of machine is made of. A template has a name, a type, a manufacturer, a model, a PoE budget and its ports by groups. Each group gives a quantity of ports that are alike, the pattern of their names, where `{m}` stands for the number of the member of the device and `{n}` for the number of the port, a first number, a connector, a speed and a PoE standard. The groups are added, removed and put in order, and the form shows the names the ports get as the groups are typed; a pattern that cannot name ports and a group that repeats a name are told on their line.
- Five generic templates, without a brand, are added on demand from the list of templates: switches with 8, 24 and 48 ports, a firewall and a server. Those that are missing by their name are created; the others are left as they are, even changed.
- Members of a device: the boxes it is made of, one for a switch alone, several for a stack or for firewalls managed as one. Each member has a number in its device, from 1 to 16, a role, an optional template, a serial number, a description and an optional room of the site of the device, for a member kept apart from it. A device has one primary member and one secondary member at most. The page of a device lists its members, which are added, modified and deleted in a window.
- A member added with a template gets its ports in the same action, named by the patterns of the template with the number of the member: `2/0/1` to `2/0/24` on the member 2. On a device without a member, "Choose a template" creates its member 1, primary, with the ports of the template. Changing a template afterwards, or the template of a member, changes no port. Deleting a member deletes its ports, after a confirmation that gives their number, and deleting a device deletes its members. Each member and each port created or deleted is an entry of the activity log.
- The ports of a device, on its page, grouped by member: name, state, mode and VLANs, aggregate, connector and speed, PoE and description. A series of ports is added to a member without a template, with a pattern for their names, a first and a last number, a connector, a speed and a PoE standard, 128 ports at a time at most; on a device without a member, "Add ports" creates its member 1, primary. The order of the ports of a member stops at 9999: a series that would go past it is refused whole, and says to give a lower place to the last ports first. The name of a port is free and unique within its device, whatever its case.
- The window of a port changes its name, its place in the order of its member, its state, enabled or disabled, its connector, its speed, what it powers and its description. Ports are ticked one by one, by range with a click then a click with the Shift key, or a whole member at a time; what is ticked is enabled, disabled or deleted together, for all the ports ticked or for none, with one entry of the activity log per port changed.
- The mode of a port and the VLANs it carries, chosen in the window of the port among the VLANs of the site of its device, each with its number and its name. In access mode, one untagged VLAN and, behind a box to tick, a voice VLAN, tagged, for a telephone with a workstation behind it; in trunk mode, a native VLAN at most and the tagged VLANs; without a mode, no VLAN. The table of ports sums up each port: "Accès 10", "Accès 10, voix 30", "Trunk, natif 99 : 10, 20, 30".
- The ports ticked get the same mode and the same VLANs in one action, or lose their mode together: for all of them or for none, with one entry of the activity log per port changed. A refusal names the ports in the way, ten at most.
- Aggregates of ports, on the page of a device: several of its ports used as one link, on one or several of its members, with a name unique within the device, a protocol, LACP or static, and a description. The mode and the VLANs are set on the aggregate, by the rules of a port. The window of an aggregate ticks its ports among those of the device and says, before anything is saved, which ports lose their own mode and their own VLANs by joining it. In the table of ports, a port of an aggregate names it in place of its own VLANs. Deleting an aggregate frees its ports, without a mode nor a VLAN.
- The page of a VLAN lists the ports and the aggregates of ports that carry it, device by device, each with the way it is carried: untagged, voice, native or tagged.
- The demonstration network gains the generic templates, a stack of two members, two access switches, a firewall and a small switch of the workshop with their ports, access ports with a voice VLAN, trunks, two LACP aggregates that span the two members of the stack, and a few ports shut down that say why in their description. Its existing devices get their members: none is created again.
- A room can be moved to another floor, and a floor to another building, from their window, within the same site: the devices kept in the rooms follow. A place never changes site.

### Changed

- The form of a device no longer asks for its serial number once the device has members: each member has its own. A number typed before stays as it is, and is still shown on the page of the device.
- A VLAN is not deleted while a port or an aggregate carries it: the refusal names the devices and their ports, ten ports at most, then counts the others. A VLAN that is carried is not moved to another site either.
- A device whose ports carry VLANs is not moved to another site: the refusal says to remove their mode first.
- A room is not deleted while a member of a device is kept in it, and the members of a device moved to another site leave their rooms, as the device does.
- In the table of the addresses of a subnet, the device of an address is written under its host name instead of in a column of its own: on a screen 1280 pixels wide the table no longer scrolls sideways, and the actions of each line stay in sight.
- The page that creates the devices of a site from host names proposes a status for each line, which can be changed: planned on a planned site or when every address of the name is planned, retired when every address is retired, active otherwise. The devices used to be created active.
- Attaching an address to a device and detaching it are events of their own in the activity log, "Address attached" and "Address detached". They name the device and its site, and are offered among the filters of the log. The entries written before as "IP address updated" stay as they are.

## [0.7.0] - 2026-10-12

Upgrading from 0.6.0 keeps every object. The migrations add the status of sites, which every existing site gets as "active", the tables of buildings, floors, rooms and devices, and the link between an address and its device, left empty. Nothing changes on the pages of an installation until a place or a device is declared.

### Added

- Sites have a status, planned, active or retired, shown in the list of sites, which can be filtered on it, on the page of each site and in its window.
- Buildings, floors and rooms are declared on the page of their site, shown as a tree: the buildings, the floors of each from the lowest up, and the rooms of each floor with the number of devices kept in them. A room can be marked as technical. Each level is created, modified and deleted in a window; a place that still holds something is not deleted, and the refusal says what to delete first.
- Devices, reached from the menu: a name that is unique within its site whatever its case, a type, a site, an optional room of that site, a manufacturer, a model, a serial number, a status and a description. The list of devices is filtered by site, type and status and searched by name, model and serial number, and the page of a site lists its own.
- An IP address can be attached to a device, of which it becomes an interface; it keeps its host name and its type. The page of a device lists its addresses with their subnet and their VLAN. From there an existing address is looked for on every site, by its first digits or by its host name, and attached; a new one is created in a subnet chosen in the window; one address or all of them are detached.
- The window of an address chooses its device, the devices of the site of the subnet first, and the table of the addresses of a subnet shows the device of each one.
- A device is not deleted while an address is attached to it: the refusal offers to detach them all.
- A page of each site creates its devices from the host names of its addresses in one action: one line per distinct name, whatever its case, with its addresses and the type proposed for the device. A name the site already has a device for attaches its addresses to that device. Everything ticked is done, or nothing is, each refusal is told on its line, and no host name is changed.
- A new deviation: an address attached to a device of another site than its subnet, shown on the page of the device and in the table of the addresses of the subnet. A subnet without a site gives none.
- The demonstration network gains a building with its floors and rooms for each of its two sites, about thirty devices kept in those rooms and given the addresses that bear their name, many host names left without a device, and an address of the workshop attached to a server of the head office. Its third site is planned.

### Changed

- A block created along with a site, from its window or from the table of the addressing rule, takes the status of the site. It used to be active.
- A site is not deleted while a building or a device belongs to it.
- The activity log records the status of a site, every change to a building, a floor, a room or a device, and each address attached to a device or detached from it, as a change of that address.
- A window never grows past the screen: a long form scrolls inside it.
- The demonstration command refuses to run when a building or a device already exists.

## [0.6.0] - 2026-10-11

Upgrading from 0.5.1 keeps every object. The migrations add the number of sites, left empty, and the tables of the addressing rule. As long as no rule is declared, nothing is proposed and nothing new is reported.

### Added

- Sites have an optional number, from 0 to 65535 and unique, shown in the list of sites and on the page of each site.
- An addressing rule for the organisation, on its own page reached from the menu: a block of the organisation, the length of the blocks of sites, the length of the subnets of VLANs, where the gateway goes, and a standard breakdown of a subnet into ranges. The rule is checked as a whole, each refusal on its line, and the page shows what it gives while it is typed: the numbers and the VIDs it reaches, and an example. There is one rule at most; it can be deleted, which changes nothing else.
- A table on the page of the rule tells, site by site, the block the rule expects and whether it is declared, and creates a block that is missing.
- With a rule, the window of a site shows the block expected for the number being typed and can create it along with the site: both are saved, or neither.
- The forms of a VLAN and of a subnet propose the subnet the rule expects for the site and the number of the VLAN, with its gateway. The proposal follows the site and the number until a value is changed by hand, and the form says why when the rule proposes nothing.
- A box in those forms cuts the new subnet into the ranges of the rule. The same can be done afterwards on the page of a subnet that has no range: a window shows the ranges first, and all of them are created or none.
- A page applies the standard to a site in one action: each entry of the catalogue the site does not follow yet can become one of its VLANs, with its subnet and its ranges, under one status. A refusal is told on its line and nothing is created unless everything is.
- Three deviations that follow from the rule: a site whose number the rule does not reach, a numbered site that has not declared the block the rule expects, and a subnet that is not the one the rule expects for its VLAN. They are shown on the site, the VLAN, the subnet and the page of the rule, and refuse nothing.
- The demonstration network gains the numbers of its sites, an addressing rule with its ranges, a third site that is being planned and has no VLAN yet, and a subnet addressed after another number than the one of its VLAN.

### Changed

- The address block the addressing rule starts from can no longer be deleted, given to a site, or shrunk to the length of the blocks of sites, as long as the rule starts from it.
- With a rule that has a standard breakdown, the box that applies it is ticked by default in the form of a new VLAN: untick it to create the subnet without ranges.
- The activity log records the number of a site and every change to the addressing rule.

## [0.5.1] - 2026-10-11

Upgrading from 0.5.0 changes no data: there is no migration. Some deviations disappear from the pages, because they are no longer worked out for subnets that are not segments.

### Changed

- Only a subnet of the segment kind is shown as a deviation when it lies outside of the blocks of its site. A point-to-point link, a loopback, a tunnel or external addresses are often outside of them by design and are no longer reported. The other deviations still apply to every kind of subnet.
- The demonstration network keeps one segment outside of the block of its site; the public addresses of the carrier are no longer a deviation.
- Attaching several VLANs to an entry of the catalogue at once only accepts VLANs that bear the number of the entry: a VLAN with another number is refused on its line and nothing is attached. Such a VLAN is still attached one at a time, from its own form, and is then shown as a deviation.

### Fixed

- The form of an address block now says what the block of a site is: the address space that holds all of its subnets, not one of its networks. As soon as a site is chosen and a valid block is typed, it tells how many segments of the site the block would hold, and warns when it would hold none of them. Nothing is refused on that ground.
- The deviation of a subnet outside of the blocks of its site names those blocks, three at most, as in "Hors des blocs du site (10.20.1.0/24)".
- The page of a site that has no block explains what a block is.
- The catalogue now says that an entry stands for every site: at the top of its page, and in the window where an entry is created.
- Giving an entry a number or a name that another entry already has is refused with a message that names that entry, says what to do instead of creating a second one, and links to its page.
- The page of a VLAN that follows no entry offers a button to attach it to the entry that has its number, when its site does not follow that entry yet. The button is shown to editors and administrators only.

## [0.5.0] - 2026-10-11

Upgrading from 0.4.1 keeps every object. The migrations give each subnet the site of its VLAN and a kind: a /31 with neither gateway nor range becomes a point-to-point link, a /32 with no VLAN, gateway or range a loopback, anything else a segment. DHCP ranges become ranges of the DHCP kind.

### Added

- A VLAN catalogue of the organisation: the number and the name a VLAN is expected to have on every site, each unique in the catalogue. Its main view crosses the entries with the sites and shows, in each cell, the number of the VLAN of that site that follows the entry.
- A VLAN can follow an entry of the catalogue, chosen in its form: a new VLAN starts with the number and the name of the entry, which stay free. An entry is followed by one VLAN per site at most. From the page of an entry, the VLANs that already bear its number are attached to it in one action: all of them or none, with each refusal on its line.
- A code for each site: letters, digits and hyphens, ten at most, unique, written in capitals.
- A page for each site: its code, its description, its address blocks, its VLANs with their entry of the catalogue, its subnets that no VLAN carries, and its deviations.
- Address blocks, for the organisation or for one site, listed as a tree by nesting. The page of a block shows the subnets it holds, how much of it they take, and what is left as the largest prefixes that fit (the first fifty are listed, all are counted). Two blocks of sites that overlap, a block of a site around a block of the organisation, and a block inside a subnet are refused, with the name of what is in the way; a block may be exactly a subnet.
- A kind for each subnet: segment, point-to-point, loopback, tunnel or external. A point-to-point link is a /30 or a /31 and a loopback a /32; neither has a gateway or ranges, and a loopback or a tunnel is not carried by a VLAN. A change of kind is refused in one message that lists everything in the way.
- A site for each subnet: the site of its VLAN when it has one, or one chosen freely for a carrier link, a tunnel or a loopback.
- A kind and a name for each range: DHCP, static or reserved. Two ranges of a subnet never overlap, whatever their kinds; a static or a reserved range may hold the gateway and static addresses.
- A ninth state on the address plan, the reserved range, drawn as a fine pattern of dots.
- Deviations: a VLAN whose number or name differs from its entry of the catalogue, a subnet in a block of another site, a subnet outside of the blocks of its site, a subnet without a site in the block of a site. Each one is shown with its reason where it matters (VLAN, subnet, site, block, catalogue), is never stored and never refuses anything.
- The activity log records the creations, updates and deletions of catalogue entries, blocks and ranges, and every attachment of a VLAN to the catalogue.
- The demonstration network gains a catalogue, site codes, blocks, ranges of the three kinds, a subnet of each kind, and two deviations explained in their descriptions.

### Changed

- The health check at `/up` answers `Unavailable` with a 503 status, in plain text and without any other detail, while the application is in maintenance mode (`php artisan down`), and `OK` again after `php artisan up`. A planned maintenance is to be declared in the monitoring tool.
- After a quick entry, the message reads "1 adresse IP créée." or "3 adresses IP créées.", with the number first.
- DHCP ranges are now ranges with a kind: the section of a subnet page is called "Plages", each range shows its kind, and a range must state its kind when it is created or changed.
- The next free address of a subnet is the lowest free address of a static range and, failing that, the lowest one outside of every range; an address of a reserved range is never proposed. The page says where the address was taken from, or that there is none to propose. The quick entry proposes addresses the same way.
- The free addresses of a subnet are cut at the bounds of its ranges, and each interval says whether it lies in a static range, in a reserved one or outside of every range.
- When a VLAN moves to another site, its subnet follows in the same action.
- A site is no longer deleted while a subnet or an address block belongs to it.
- The list of VLANs has a catalogue column and filter; the list of subnets has site and type columns and filters; the list of sites shows the code and leads to the page of each site.
- In the activity log, the entries written about DHCP ranges before this version are kept as they were and still read "Plage DHCP"; filtering on the creation, update or deletion of ranges finds them too.

## [0.4.1] - 2026-10-11

### Changed

- The health check at `/up` answers one word of plain text: `OK` when the database can be reached, `Unavailable` with a 503 status when it cannot, without any detail. It is no longer a page, it is never cached, it keeps answering while the application is in maintenance mode, and it is limited to 60 requests a minute per address.

### Fixed

- Each state of the address plan has a shape of its own, in the plan and in its legend, so that two states can be told apart without their colour: an empty cell for a free address, a struck-through cell for an unusable one, a full cell for a documented one, a full cell with a dot for the gateway, a half-full cell for a DHCP reservation, a dashed outline for a planned address, a thick outline for a retired one, fine stripes for a DHCP range.
- The licences of the two embedded typefaces are shipped with the built application, next to the font files: `public/build/assets/barlow-LICENSE.txt` and `public/build/assets/ibm-plex-mono-LICENSE.txt`.
- The search of the table of addresses compares what is typed with MAC addresses only when it can be a piece of one: "compta" no longer finds a MAC address that contains "ca", while `AA-BB`, `aa:bb` and `aabb` all find `aa:bb:cc:dd:ee:ff`.
- The QR code of two-factor authentication is dark on white in both themes, with a white margin around it: an authenticator application reads it in the dark theme too.
- A noun agrees with the number in front of it: "1 ligne sur 100" in the quick entry, "1 adresse" for a DHCP range, "1 entrée" under a list, "1 seconde" before a locked login can be tried again. A site that still has one VLAN, or a subnet that still has one address, says so in the singular when its deletion is refused.

## [0.4.0] - 2026-10-11

### Added

- A visual identity of its own, inspired by a patch bay: a dark theme in graphite grey and a light theme, with the four pair colours of a network cable used as codes (orange for VLANs and DHCP, green for what is active, blue for information, brown for what is retired).
- A logo, two interlaced strands, and its icons for the browser tab and for telephones.
- Two embedded typefaces, Barlow for the text and IBM Plex Mono for addresses and other technical values. They are served by the application: nothing is loaded from another host.
- An address plan on the page of a subnet: one cell per address, like the ports of a patch panel, with its state (unusable, gateway, documented, planned, retired, DHCP range, DHCP reservation, free), a legend and a tooltip per cell. It is drawn for subnets of 512 addresses at most.
- The next free address of a subnet is put forward on its page, with a button that opens the window of a new address already filled in.
- Error pages (page not found, access denied, expired page and the like) in the colours of the application, in French.
- Browser tests of the main journeys, run with `npm run test:e2e`.

### Changed

- The dark theme is the theme of a user who never chose one. The light, dark and system choices remain in the appearance settings, and a choice already made is kept.
- The status of an IP address agrees with it in French: "active", "prévue", "retirée", in tables, forms and the activity log. VLANs and subnets keep "actif", "prévu", "retiré".
- The page of a subnet is reorganised: its address in large type with the label of its VLAN and its status, a band of facts, the address plan, the table of addresses, then the DHCP ranges and the free addresses beside or under it.
- The sidebar shows the administration entries in a group of their own, marks the current entry with a green light, and shows the role of the signed-in user with the version.
- The sidebar leaves the page under 1024 pixels wide instead of 768, and closes by itself once an entry is chosen.
- In tables, the actions of a line are two small buttons with an icon, named "Modifier" and "Supprimer" for assistive technologies and on hover.
- A status is shown as a dot and a label, a VLAN as an orange label.
- The login page says "Rester connecté sur cet appareil" and reminds that an administrator can reset a lost password.
- A field in error is outlined in the error colour in every form, with its message under it.
- The keyboard focus shows the same outline on every interactive element, and animations stop when the system asks for reduced motion.
- A table or an address plan wider than the screen scrolls inside its own frame: the page itself never scrolls sideways.

### Fixed

- The initial of the application in the sidebar was white on white in the dark theme: the logo replaces it.

### Security

- The theme kept in the browser is checked against the known themes before it is written into a page.

## [0.3.0] - 2026-10-10

### Added

- Sites: the places whose network is documented. A site is created, renamed and deleted from its list.
- VLANs, attached to a site: number from 1 to 4094, name, description and status. The number and the name are unique within a site, and the list can be filtered by site and by status.
- Subnets, with or without a VLAN: address typed in CIDR notation or with a prefix length chosen with its mask, gateway, name, description and status. A VLAN carries one subnet at most, and both can be created in a single action.
- DHCP ranges, managed from the page of their subnet.
- IP addresses, managed from the page of their subnet: host name, MAC address, type of device, static address or DHCP reservation, status and description. Only the address is required. The table can be sorted and searched by address, host name or MAC address.
- Quick entry of several IP addresses from the keyboard alone: each new line proposes the next free address, and up to 100 lines are saved at once, or none if one of them is refused.
- The page of a subnet shows its mask, its broadcast address, its usable addresses, its occupancy and its free addresses as intervals.
- Each object has a status: planned, active or retired.
- The addressing plan is kept consistent: no overlapping subnets, no address outside its subnet or documented twice, no static address inside a DHCP range, no gateway outside its subnet. Each refusal says what is in the way.
- MAC addresses are accepted in the four usual notations and stored in a single one.
- The activity log records every creation, update and deletion of these objects, with the values before and after.
- `torsade:demo` command, which fills an empty installation with a fictitious network to try the application. It never runs in production.
- The navigation gives access to sites, VLANs and subnets, and the home page says where to start until a first site exists.

### Changed

- Beyond the limit of attempts, a two-factor code is refused with a message in the form, as for a locked login, instead of an error page.
- The activity log names the object concerned by each entry, whether an account or a network object.

### Security

- Search engines are told not to index the application, by `robots.txt` and by a header on every response.
- Readers can consult the network documentation but cannot change it: every write is checked on the server.

## [0.2.0] - 2026-10-10

### Added

- Administrators can require two-factor authentication on an account. Its user is then sent to a setup page until it is configured, and can no longer disable it.
- Administrators can reset the two-factor authentication of an account whose device is lost. This closes every session of the account.
- `torsade:reset-two-factor` command, to do the same from the server when the only administrator has lost both their device and their recovery codes.
- The user list shows the two-factor status of each account: enabled, required and still to be set up, or disabled.
- The activity log records when two-factor authentication is enabled or disabled by a user, and when it is required, no longer required or reset by an administrator.
- The version of the application is shown at the bottom of the sidebar to signed-in users, and by `php artisan about`.

### Changed

- Changing your own password now closes your other sessions. The session you are using stays open.
- A password change counts as a password confirmation: the pages that ask to confirm it do not ask again right after.
- The name of the application replaces the previous logo in the sidebar and on the login page, with a provisional neutral icon.
- Error pages (403, 404, 419, 429, 500 and 503) are in French.
- Building the front end requires Node.js 22.18 or later, in the 22 line.

### Security

- A required two-factor authentication cannot be disabled by its user, even by calling the server directly.
- An administrator cannot require or reset two-factor authentication on their own account through the administration pages.
- The PHP version is no longer announced in the response headers.

## [0.1.0] - 2026-10-08

### Added

- Login by e-mail address and password, with no public registration: accounts are created by an administrator.
- `torsade:create-admin` command, which creates the first administrator with a random password shown once. There are no default credentials.
- Forced password change at the first login and after every password reset.
- Three roles: reader, editor and administrator.
- User administration for administrators: creation, update, deactivation, reactivation and password reset. Accounts are deactivated, never deleted.
- `torsade:reset-password` command, since there is no password reset by e-mail.
- Optional two-factor authentication with an authenticator application and recovery codes.
- Activity log for administrators: account changes with the values before and after, logins, failed logins, logouts, password changes and resets, and console commands. It is read-only and can be filtered by user, event and period.
- Interface in French.

### Security

- Logins are locked for a minute after five failures for the same e-mail address and IP address.
- Passwords must be at least 12 characters long.
- The application makes no request to any external service.
