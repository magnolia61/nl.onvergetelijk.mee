# nl.onvergetelijk.mee

## Functionele beschrijving

De `mee`-extensie berekent of een persoon dit kamp, dit jaar of een specifiek kampjaar daadwerkelijk "meegaat" — als deelnemer of als begeleider. Het resultaat is een overzichtelijke array met vlaggen en tekstuele statussen die door andere modules (zoals `core`, `acl` en `email`) worden gebruikt om te beslissen welke groepen, accounts en emailadressen iemand moet hebben.

`mee` houdt rekening met alle nuances: een persoon kan positief ingeschreven staan voor dit specifieke event, of voor een ander kamp dit jaar; kan meegaan als testdeelnemer, als staf, als begeleider van een ander kamp ("waarnodig"), of als topdagdeelnemer. Voor elk van deze scenario's berekent `mee` een apart vlag.

## Afhankelijkheden

- `nl.onvergetelijk.base`
- `nl.onvergetelijk.partstatus` (voor statusdefinities)

---

## Technische documentatie

### Kernfunctie

`mee_civicrm_configure($contact_id, $allpart_array, $array_partditevent, $array_status, $array_criteria)` — de hoofdmotor (±1100 regels). Berekent per categorie of de persoon "mee" gaat:

- **MEE 1.x — Dit event (deelnemer)**: positief ingeschreven voor dit specifieke event (deel, top, deeltest)
- **MEE 2.x — Dit event (leiding)**: positief ingeschreven als begeleider voor dit event (leid, waarnodig, staf, leidtest)
- **MEE 3.x — Dit jaar (deelnemer)**: minstens één positieve deelnemersinschrijving dit jaar
- **MEE 4.x — Dit jaar (leiding)**: minstens één positieve leidingsinschrijving dit jaar
- **MEE 5.x — Eventjaar (deelnemer)**: minstens één positieve inschrijving in het kampjaar van dit event
- **MEE 6.x — Eventjaar (leiding)**: idem voor leiding

De retourarray bevat naast de vlaggen ook tellers (aantallen positieve inschrijvingen) en tekstuele statussen voor gebruik in Smarty-templates.

### Hooks geïmplementeerd
- `civicrm_config`, `civicrm_install`, `civicrm_enable`
- Geen directe hook-registratie; wordt altijd aangeroepen vanuit `core_civicrm_custom`.

### Retourstructuur
De retourarray is een merge van drie deelresultaten. Elke variabele kan waarde 0 (nee), 1 (ja) of 3 (niet bepaald / fout) hebben; tekstsleutels geven YES/NOT/ANN/MSS/STF/ERR.

```
diteventdeelyes / diteventdeelnot / diteventdeelmss  → deelnemer dit specifieke event
diteventdeeltop / diteventdeeltst / diteventdeelstf  → topkamp / test / staf dit event (deel)
diteventleidyes / diteventleidnot / diteventleidmss  → leiding dit specifieke event
diteventleidtst / diteventleidstf                    → test / staf dit event (leid)
diteventdeeltxt / diteventleidtxt                    → tekststatus dit event

ditjaardeelyes / ditjaardeelnot / ditjaardeelmss     → deelnemer dit jaar
ditjaardeeltop / ditjaardeeltst / ditjaardeelstf     → topkamp / test / staf dit jaar (deel)
ditjaarleidyes / ditjaarleidnot / ditjaarleidmss      → leiding dit jaar
ditjaarleidtst / ditjaarleidstf                      → test / staf dit jaar (leid)
ditjaardeeltxt / ditjaarleidtxt                      → tekststatus dit jaar

eventjaardeelyes / eventjaardeelnot / eventjaardeelmss → deelnemer het kampjaar van dit event
eventjaardeeltop / eventjaardeeltst / eventjaardeelstf → topkamp / test / staf dat kampjaar (deel)
eventjaarleidyes / eventjaarleidnot / eventjaarleidmss → leiding dat kampjaar
eventjaarleidtst / eventjaarleidstf                    → test / staf dat kampjaar (leid)
eventjaardeeltxt / eventjaarleidtxt                    → tekststatus dat kampjaar

contact_id / displayname                             → contactgegevens
```

---

*Beheerd door Stichting Onvergetelijke Zomerkampen.*
