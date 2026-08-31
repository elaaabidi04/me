# Script de présentation — « me » (v3, corrigée après revue façon jury)

> Cette version corrige les vulnérabilités identifiées lors d'une relecture critique : affirmations absolues sur la concurrence, comparaisons caricaturales, statistique non vérifiée, promesse de "compréhension" trop forte, économie du modèle thérapeute pas défendable, produit présenté comme trois projets à la fois. Chaque changement est expliqué dans les notes "Pourquoi ce changement" sous les slides concernées. Sources vérifiées en bas de fichier.
> Durée cible : ~9-10 minutes. Pour chaque slide : **Contenu à l'écran** puis *le texte dit à voix haute*.

---

## Slide 1 — Titre

**Contenu à l'écran**
- **me**
- *Écrire. Comprendre. Évoluer.*
- Un journal intelligent qui transforme l'auto-réflexion en compréhension — et, si tu le souhaites, en continuité avec un accompagnement humain.

**Script**
*Bonjour à tous. Je vais vous présenter « me » — un journal intelligent, pensé comme un vrai produit, avec un vrai modèle économique et une vraie architecture derrière. Pas juste un chatbot qui répond à un texte : un système qui construit, avec le temps, une compréhension des patterns d'une personne.*

---

## Slide 2 — Le problème

**Contenu à l'écran**
- Écrire pour aller mieux est une pratique reconnue — mais la plupart des journaux ne renvoient rien à l'utilisateur
- Résultat documenté : la majorité des utilisateurs abandonnent leur journal après quelques semaines
- Les apps existantes analysent l'humeur (tracker) ou génèrent du texte (chatbot) — peu relient les deux dans la durée

**Script**
*On écrit pour mieux comprendre ce qu'on vit. Mais la plupart des journaux — papier ou numériques — ne nous renvoient rien : on écrit, ça se stocke, et on oublie. Résultat : la majorité des gens abandonnent après quelques semaines. Les apps qui existent aujourd'hui font déjà des choses utiles — certaines trackent l'humeur, d'autres génèrent des réponses IA — mais peu relient ce qu'on écrit à ce qui se répète dans le temps.*

---

## Slide 3 — Le problème, concret

**Contenu à l'écran**
- Tu écris aujourd'hui. Tu écris dans deux semaines. Tu écris dans un mois.
- La même situation, la même émotion, le même besoin peuvent revenir — sans que tu le remarques toi-même
- Exemple : plusieurs conflits professionnels, chacun noté isolément, jamais reliés entre eux

**Script**
*Concrètement : tu écris aujourd'hui, tu écris dans deux semaines, tu écris dans un mois. La même situation peut revenir trois fois sans que tu fasses le lien toi-même — parce que chaque entrée reste isolée. C'est exactement ce point que « me » attaque : pas remplacer l'écriture, mais relier les entrées entre elles.*

---

## Slide 4 — La solution

**Contenu à l'écran**
- **me** — un journal qui relie chaque entrée aux suivantes, avec ton accord à chaque étape
- Entrée libre → émotions, déclencheurs, besoins → patterns qui se répètent → tendances visibles
- Un compagnon de réflexion conversationnel, pas un substitut à un professionnel
- Une mémoire construite avec toi, jamais imposée
- Un pont optionnel vers un thérapeute, activé uniquement si tu le choisis

**Script**
*« me » part d'une entrée libre, en extrait des émotions, des déclencheurs et des besoins, puis relie ces éléments dans le temps pour faire émerger des patterns que la personne ne voyait pas forcément seule. Le compagnon conversationnel n'est pas un thérapeute — on le dit explicitement dans le produit — et la mémoire n'est jamais imposée : elle se construit avec l'accord de la personne, étape par étape.*

> **Pourquoi ce changement** — on a remplacé « coach » par « compagnon de réflexion » et retiré « comprend vraiment ». Un LLM n'a pas de compréhension au sens humain ; il infère des informations à partir du texte. Dire « comprend vraiment » est une promesse qu'un jury technique peut démonter en une phrase. « Analyse pour faire émerger des patterns » décrit exactement ce que fait le système, et reste défendable.

---

## Slide 5 — Démonstration

**Contenu à l'écran**
> *Entrée utilisateur :* « Encore une dispute avec mon collègue aujourd'hui. Je pensais que ça ne m'affectait pas, mais je suis rentré complètement épuisé. »

**me détecte :**
- Émotion : frustration (intensité 7/10)
- Déclencheur : conflit professionnel
- Besoin possible : reconnaissance, espace
- Pattern relevé : conflits professionnels récurrents (3 entrées ce mois-ci)

**me répond :**
> « Je remarque que les conflits au travail reviennent plusieurs fois dans tes dernières entrées. Tu veux qu'on regarde ce qui se répète ? »

**Script**
*Voici un exemple concret. La personne écrit une entrée ordinaire sur une dispute au travail. « me » en extrait l'émotion, son intensité, le déclencheur et un besoin possible — pas juste des mots-clés, une structure. Et parce que ce n'est pas la première fois que ce type de conflit apparaît ce mois-ci, le système le signale, sans donner de diagnostic, juste une observation vérifiable dans l'historique de la personne. C'est ça, la vraie proposition de valeur : transformer une entrée isolée en une observation utile.*

---

## Slide 6 — La mémoire

**Contenu à l'écran**
- Le système ne mémorise rien automatiquement — chaque élément passe par une évaluation de pertinence
- Journal → extraction structurée → évaluation → proposition à l'utilisateur → confirmation ou refus → mémoire long terme
- L'utilisateur peut à tout moment relire, corriger ou supprimer ce qui a été retenu
- L'IA ne décide pas seule de ce qui définit la personne

**Script**
*La mémoire est probablement la partie la plus travaillée techniquement. Ce n'est pas l'historique complet renvoyé au modèle à chaque fois — chaque élément candidat passe par une étape d'évaluation, puis est proposé à l'utilisateur, qui confirme, corrige ou refuse. Rien n'est mémorisé sans ce passage. Et tout ce qui a été retenu reste visible et modifiable dans l'application, à tout moment.*

> **Pourquoi ce changement** — un jury technique pose forcément la question « comment savez-vous ce qui compte ? ». Décrire le pipeline réel (extraction → évaluation → consentement) transforme une phrase marketing fragile en argument technique solide.

---

## Slide 7 — Le tableau de bord

**Contenu à l'écran**
- Émotions et intensité dans le temps
- Déclencheurs et catégories de stress récurrents
- Besoins exprimés, comportements associés
- Chaînes de patterns : déclencheur → émotion → pensée → comportement
- Ce qui aide, observé dans les entrées elles-mêmes

**Script**
*Le tableau de bord agrège ces extractions sur plusieurs semaines et plusieurs mois : quelles émotions reviennent, quels déclencheurs, quels comportements y sont associés, et surtout, quelles chaînes se répètent — tel déclencheur mène souvent à telle émotion, puis à tel comportement. C'est là que le produit devient plus qu'une conversation avec une IA : c'est une lecture longitudinale que la personne ne pourrait pas facilement reconstituer seule.*

---

## Slide 8 — Le pont vers un accompagnement humain

**Contenu à l'écran**
- L'IA ne remplace pas un professionnel — elle prépare mieux le terrain entre deux séances
- Ce qui est partagé, avec un thérapeute lié : des synthèses de tendances choisies par l'utilisateur, jamais le journal complet par défaut
- Le partage est activé élément par élément, révocable à tout moment
- Bénéfice pour le professionnel : arriver en séance avec une synthèse structurée de ce qui s'est passé entre les rendez-vous, plutôt que de repartir de zéro

**Script**
*Si un jour la personne est suivie par un thérapeute, ou souhaite l'être, elle peut choisir de partager certaines synthèses — pas le journal brut, des tendances qu'elle sélectionne elle-même, et qu'elle peut retirer à tout moment. Pour le professionnel, le bénéfice est concret : au lieu d'arriver en séance avec uniquement le souvenir flou des derniers jours, il dispose, avec le consentement du patient, d'une synthèse structurée de ce qui s'est passé entre deux séances.*

> **Pourquoi ce changement** — on répond directement aux questions qu'un jury pose sur ce point : quoi est partagé, comment le consentement fonctionne, pourquoi un professionnel s'en servirait plutôt que du compte-rendu oral du patient.

---

## Slide 9 — Analyse concurrentielle

**Contenu à l'écran**

| | Comprend le texte libre (IA) | Mémoire long terme | Tableau de bord de tendances | Pont vers un thérapeute réel |
|---|---|---|---|---|
| Daylio | Partiel — notes courtes, pas d'extraction | Non | Oui (humeur / habitudes) | Non |
| Reflectly | Oui — questions personnalisées, corrélations d'humeur | Non | Oui (mensuel) | Non |
| Rosebud | Oui | Oui (payant) | Oui | Non — IA seule, pas de professionnel intégré |
| Wysa (app + Copilot) | Oui (dans l'app) | Limité | Non (dans l'app) | Oui — mais produit B2B séparé, pas relié au journal personnel |
| **me** | Oui | Oui, avec consentement | Oui | Oui, dans le même compte |

**Notre différenciation n'est pas une brique isolée — c'est la combinaison des quatre dans un seul produit, au niveau de l'utilisateur individuel.**

**Script**
*Regardons la concurrence honnêtement. Daylio permet des notes, mais reste centré sur le tap d'humeur, pas sur l'extraction de texte libre. Reflectly fait déjà de la vraie IA — questions personnalisées, corrélations d'humeur — mais sans mémoire long terme ni pont professionnel. Rosebud est notre concurrent le plus proche : mémoire long terme, tableau de bord, un vrai abonnement payant — mais l'accompagnement reste entièrement piloté par l'IA, sans lien vers un vrai professionnel. Et Wysa a lancé Copilot, un vrai pont thérapeute-patient — mais comme produit B2B séparé pour des établissements de santé, pas intégré à une app de journaling personnelle. Notre position : nous ne prétendons être seuls sur aucune de ces briques prises isolément. Notre différenciation, c'est de les combiner toutes les quatre, dans un seul produit, pour un seul utilisateur.*

> **Pourquoi ce changement** — c'est la correction la plus importante du pitch. L'ancienne version affirmait « aucun concurrent ne combine... » et caricaturait chaque concurrent (Daylio "ne comprend pas le texte", Reflectly "positivité forcée", Rosebud "mémoire verrouillée", Wysa/Woebot "se positionnent comme un substitut"). Ce sont des affirmations vérifiables et actuellement fausses ou datées : Rosebud a un tier gratuit et du texte compris par IA ; Wysa dit explicitement ne pas remplacer un professionnel et a justement lancé un pont thérapeute (Wysa Copilot, nov. 2024) ; Daylio accepte texte/photo/audio ; Reflectly propose des corrélations d'humeur et des synthèses réelles. Un jury qui connaît un seul de ces produits peut démonter la slide en une question. La version tableau reste honnête et résiste à la vérification.

---

## Slide 10 — Ce que ce n'est pas

**Contenu à l'écran**
- « me » n'est pas juste un appel à un LLM — beaucoup peuvent scripter ça avec une API
- Le produit, c'est la chaîne complète : extraction structurée → mémoire contrôlée → agrégation dans le temps → tableau de bord → sécurité → consentement → continuité avec un professionnel
- Les situations à risque suivent un chemin séparé, détecté par des règles dédiées, indépendant du dialogue normal — jamais géré uniquement par la génération de texte

**Script**
*Une question qu'on nous pose souvent : « je peux faire ça avec ChatGPT ». Un LLM seul transforme du texte en réponse. Le produit ici, c'est la chaîne complète : extraction structurée, mémoire contrôlée par consentement, agrégation dans le temps, tableau de bord, et une détection des situations à risque qui suit un chemin séparé du dialogue normal — pas géré par la même génération de texte que la conversation courante. C'est cette architecture, pas le modèle de langage seul, qui est le produit.*

---

## Slide 11 — Business model

**Contenu à l'écran**
- **B2C — abonnement freemium** : gratuit pour commencer, Premium pour débloquer la profondeur du produit
- **B2B2C — accès plateforme pour les professionnels** : un thérapeute lié paie un accès mensuel au tableau de bord clinique, indépendamment du nombre de clients liés — chaque client garde son propre abonnement (gratuit ou Premium)
- Ce découplage évite de faire porter le coût d'infrastructure IA par client sur l'abonnement du professionnel

**Script**
*Deux moteurs de revenus. Le premier, un abonnement freemium grand public. Le second : les professionnels paient pour l'accès au tableau de bord clinique — pas par client lié, mais un accès plateforme mensuel fixe. C'est un point important : chaque client garde son propre abonnement, gratuit ou premium, qui couvre déjà ses propres coûts d'usage IA. Le professionnel paie pour la couche de collaboration, pas pour l'infrastructure du client.*

---

## Slide 12 — Tarification

**Contenu à l'écran**
- **Gratuit** — journal quotidien, extraction de base, bilan hebdo simplifié
- **Premium — 7,99 $/mois ou 69 $/an** — compagnon de réflexion illimité, mémoire long terme, bilans mensuels détaillés, historique complet
- **Pro Thérapeute — 29 $/mois** (praticien indépendant, nombre de clients liés non plafonné) ou **99 $/mois** (cabinet, jusqu'à 15 praticiens) — tableau de bord clinique, synthèses partagées, fil de crise dédié, planification de séances

**Script**
*Concrètement : gratuit pour commencer, Premium à 7,99 dollars par mois pour la profondeur complète du produit. Côté professionnels, un accès plateforme à 29 dollars par mois pour un praticien indépendant, sans plafond de clients liés — puisque ce sont les clients eux-mêmes qui financent leur propre usage via leur abonnement — ou 99 dollars pour un cabinet jusqu'à 15 praticiens.*

> **Pourquoi ce changement** — l'ancien modèle facturait le thérapeute par client lié ($19/client, ou $49 pour 5 clients), ce qui donnait un revenu par client inférieur à 10 dollars — insuffisant, selon la critique reçue, pour couvrir infrastructure IA, stockage sécurisé, support et conformité. En décorrélant le prix professionnel du nombre de clients liés, et en s'appuyant sur le fait que chaque client finance déjà son propre usage via son abonnement personnel, le modèle résiste mieux à la question « comment financez-vous ça ? ».

---

## Slide 13 — Marché

**Contenu à l'écran**
- Marché mondial des apps de santé mentale : **9,6 milliards $ en 2026**, projeté à **40,9 milliards $ en 2035** (CAGR 17,4 %) — *source : Global Market Insights*
- D'autres cabinets d'études (DataM Intelligence, Fortune Business Insights) confirment l'ordre de grandeur avec des méthodologies différentes
- Un marché déjà porté par des acteurs installés (Calm, Headspace, Talkspace, Woebot) — nous ne visons pas à les remplacer, mais un segment qu'ils ne couvrent pas : le pont consenti entre auto-réflexion individuelle et professionnel de santé

**Script**
*Le marché mondial des apps de santé mentale est estimé à 9,6 milliards de dollars en 2026, avec une croissance à deux chiffres selon plusieurs cabinets d'études. Ce marché est déjà occupé par des acteurs installés — Calm, Headspace, Talkspace — qui ont une part significative. Notre ambition n'est pas de les remplacer, mais d'occuper un segment spécifique qu'ils ne couvrent pas aujourd'hui : le pont consenti entre l'auto-réflexion quotidienne d'un individu et son propre professionnel de santé.*

> **Pourquoi ce changement** — la version précédente citait un CAGR de 14,8 %, alors que la source utilisée pour les 9,6 milliards de dollars (Global Market Insights) indique en réalité 17,4 %. Elle citait aussi un chiffre de "64 % des nouvelles apps" qui n'a pas de source suffisamment solide pour résister à une question de jury — il a été retiré : le marché est déjà assez grand sans lui. On mentionne aussi les acteurs installés plutôt que de prétendre à un marché vide, ce qui est plus crédible.

---

## Slide 14 — Roadmap

**Contenu à l'écran**
- **Déjà construit** (pendant le stage, dans le prototype actuel) : journal + extraction IA + mémoire consentie + tableaux de bord hebdo/mensuels + pont thérapeute fonctionnel (tableau de bord clinique, fil de crise, partage de synthèses, planification de séances)
- **Prochaine étape** : tests utilisateurs réels à petite échelle, sur les deux côtés (client et thérapeute)
- **Ensuite** : application mobile native, marketplace de thérapeutes partenaires
- **Plus tard** : expansion multilingue (arabe, darija, anglais, français), programme B2B élargi (mutuelles, entreprises)

**Script**
*Ce n'est pas une feuille de route uniquement sur papier. La partie B2C et la partie thérapeute — tableau de bord clinique, fil de crise, partage de synthèses, planification de séances — sont déjà construites dans le prototype actuel, développées pendant ce stage. La prochaine étape, c'est de les tester avec de vrais utilisateurs des deux côtés, avant d'élargir vers le mobile natif, une marketplace de thérapeutes, puis le multilingue et le B2B élargi.*

> **Pourquoi ce changement** — la critique principale ici était de sembler vendre trois produits à la fois (app grand public + IA + plateforme clinique + marketplace + multilingue) sans hiérarchie claire, ce qui donne une impression de dispersion. La réponse n'est pas de réduire l'ambition, mais de montrer que l'essentiel n'est pas une promesse future : c'est déjà construit et fonctionnel, ce qui change complètement la crédibilité de la suite.

---

## Slide 15 — Conclusion

**Contenu à l'écran**
- **me** transforme l'auto-réflexion quotidienne en compréhension — et, si l'utilisateur le souhaite, en continuité avec un accompagnement humain
- Un marché de 9,6 milliards de dollars en forte croissance
- Un modèle économique à deux moteurs, déjà construit dans un prototype fonctionnel
- Merci — Questions ?

**Script**
*« me » transforme un journal en un vrai outil de compréhension, sur un marché en forte croissance, avec un modèle économique à deux moteurs déjà démontré dans un prototype fonctionnel. Merci pour votre attention, je suis prête à répondre à vos questions.*

---

## Annexe — 5 questions probables du jury et réponses préparées

1. **Pourquoi ce produit ?** Le journaling produit des données personnelles riches, écrites librement, mais aujourd'hui peu exploitées au-delà du simple archivage.
2. **Pourquoi maintenant ?** Les modèles de langage actuels permettent d'analyser du texte libre à un niveau de nuance qui n'était pas accessible à ce coût il y a encore quelques années.
3. **Pourquoi vous, plutôt que la concurrence ?** Nous ne prétendons pas être seuls sur chaque brique — voir slide 9. Notre différenciation est la combinaison de l'extraction structurée, de la mémoire consentie et du pont professionnel intégré, dans un seul produit.
4. **Pourquoi les utilisateurs reviendraient, alors que la plupart abandonnent leur journal ?** Parce que chaque entrée nourrit une lecture cumulative — le produit rend quelque chose en retour (patterns, tendances), contrairement à un journal qui ne fait qu'archiver.
5. **Pourquoi ce n'est pas dangereux, pour un sujet aussi sensible que la santé mentale ?** Positionnement explicite de non-substitution, mémoire jamais automatique (toujours confirmée par l'utilisateur), détection des situations à risque par un chemin séparé du dialogue normal, et orientation vers un humain quand c'est pertinent.

---

## Sources (recherche web, vérifiées avant cette version)

- [Mental Health Apps Market Size, Forecasts Report 2026-2035 — GM Insights](https://www.gminsights.com/industry-analysis/mental-health-apps-market) — 9,6 Md$ en 2026 → 40,9 Md$ en 2035, CAGR 17,4 %
- [Mental Health Apps Market — DataM Intelligence](https://www.datamintelligence.com/research-report/mental-health-apps-market)
- [Mental Health Apps Market — Fortune Business Insights](https://www.fortunebusinessinsights.com/mental-health-apps-market-109012)
- [Rosebud — Pricing (Knowledge Base)](https://help.rosebud.app/getting-started/pricing) — tier gratuit + Premium ~9,99–12,99 $/mois selon la période
- [Wysa Copilot — Hybrid Digital and Human Therapy Platform](https://www.wysa.com/wysa-copilot) — partage de transcripts consenti, continuité entre professionnels
- [Wysa Unveils Wysa Copilot — Businesswire](https://www.businesswire.com/news/home/20241105716965/en/Wysa-Unveils-Wysa-Copilot-to-Elevate-Mental-Health-Therapy-With-AI) — lancement nov. 2024
- [Daylio Journal — Reflection.app review](https://www.reflection.app/journaling-apps/daylio) — entrées texte/photo/audio, statistiques d'humeur et d'habitudes
- [Reflectly — Journal & AI Diary](https://mwm.ai/apps/reflectly-journal-ai-diary/1241229134) — questions personnalisées, corrélations d'humeur, synthèses hebdo/mensuelles

> Le modèle de revenus et les prix de « me » restent des **hypothèses de positionnement** construites par comparaison avec ces concurrents réels, pas des données déjà validées par un test de marché.
