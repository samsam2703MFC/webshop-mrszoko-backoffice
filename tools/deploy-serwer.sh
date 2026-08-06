#!/usr/bin/env bash
# =============================================================================
#  deploy-serwer.sh — le déploiement lancé DEPUIS LE SERVEUR lui-même.
#
#  POURQUOI CELUI-CI EN PLUS DE deploy-manuel.sh. L'autre pousse depuis une
#  machine de travail vers le serveur : il lui faut rsync, sshpass et le mot de
#  passe SSH. Or quand on est déjà connecté en SSH sur la machine, tout ça est
#  du détour — et le dépôt est PUBLIC, donc le serveur peut se servir seul.
#
#  CE QU'IL NE DEMANDE PAS :
#   · pas de mot de passe SSH — on est déjà dedans ;
#   · pas d'identifiants MySQL — ils sont déjà dans config.local.php, sur cette
#     machine. Les redemander, c'est les faire retaper, donc les faire passer
#     par un historique de shell.
#
#  ATTENTION AU DISQUE. Cette machine tourne à 93 % de 24,5 Go. Le clone est
#  donc superficiel (--depth 1, un seul commit) et effacé à la fin, quoi qu'il
#  arrive. Un déploiement qui remplit le disque casse la boutique qu'il devait
#  mettre à jour.
#
#  Usage, en root sur le serveur :
#      curl -fsSL https://raw.githubusercontent.com/samsam2703MFC/webshop-mrszoko-backoffice/main/tools/deploy-serwer.sh | bash
#  ou, si le fichier est déjà là :
#      bash deploy-serwer.sh
# =============================================================================
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/var/www/html/mrszoko}"
DEPOT="${DEPOT:-https://github.com/samsam2703MFC/webshop-mrszoko-backoffice.git}"
BRANCHE="${BRANCHE:-main}"
TRAVAIL="$(mktemp -d /tmp/wsm-deploy.XXXXXX)"
# Le ménage est un piège, PAS une politesse : sans lui, un échec en cours de
# route laisse un clone de plusieurs dizaines de méga sur un disque à 93 %.
trap 'rm -rf "$TRAVAIL"' EXIT

echo "══ 0/6 · place disponible ═════════════════════════════════════════════"
DISPO=$(df -Pm /tmp | awk 'NR==2 {print $4}')
echo "  /tmp : ${DISPO} Mo libres"
[ "$DISPO" -lt 300 ] && { echo "  ^ moins de 300 Mo : libérez de la place avant (le clone + l'assemblage n'y tiendraient pas)"; exit 1; }
[ -d "$DEPLOY_DIR" ] || { echo "  ^ $DEPLOY_DIR n'existe pas — mauvais serveur ou DEPLOY_DIR à préciser"; exit 1; }
command -v git >/dev/null || { echo "  ^ git absent : apt install -y git"; exit 1; }
command -v php >/dev/null || { echo "  ^ php absent"; exit 1; }

echo "══ 1/6 · récupération du code ($BRANCHE) ══════════════════════════════"
git clone --depth 1 --branch "$BRANCHE" --quiet "$DEPOT" "$TRAVAIL/depot"
cd "$TRAVAIL/depot"
echo "  $(git log -1 --format='%h %s' | cut -c1-72)"

echo "══ 2/6 · assemblage ═══════════════════════════════════════════════════"
BO=mrszoko/backoffice
rm -rf site && mkdir -p site/backoffice site/landing site/shop/media
cp -r "$BO"/_ds site/backoffice/
mkdir -p site/backoffice/img && cp "$BO"/img/logo.png site/backoffice/img/
cat > site/backoffice/index.html <<'HTML'
<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="0; url=login.php">
<title>Konsola Mister Szoko</title></head>
<body><p><a href="login.php">Przejdź do logowania</a></p></body></html>
HTML
# php-api → api : le chemin que la console dérive de sa propre adresse.
# data/ et *.sqlite ne partent JAMAIS : ici la base est MySQL, et la base
# locale du dépôt écraserait… rien, mais elle n'a rien à faire en production.
mkdir -p site/backoffice/api
( cd "$BO"/php-api && tar -cf - --exclude=data --exclude='*.sqlite' . ) \
  | ( cd site/backoffice/api && tar -xf - )
# TOUT le PHP du répertoire, jamais une liste blanche : sept écrans livrés ont
# déjà été oubliés d'une liste tenue à la main et sont restés morts des
# semaines en production.
cp mrszoko/backoffice/*.php mrszoko/backoffice/console.css site/backoffice/

cp -r mrszoko/landing/. site/landing/

( cd mrszoko/shop && tar -cf - --exclude=router.php --exclude=serve.sh . ) \
  | ( cd site/shop && tar -xf - )
cp mrszoko/shop/.htaccess site/shop/.htaccess
cp mrszoko/shop/media/.htaccess site/shop/media/.htaccess
cp mrszoko/.htaccess site/.htaccess

{
  echo "/* Généré au déploiement depuis design-system/tokens/*.css — ne pas éditer. */"
  for f in colors typography spacing radius shadow motion fonts; do
    echo ""; echo "/* ---- $f ---- */"
    grep -v "^@import" "mrszoko/design-system/tokens/$f.css"
  done
} > site/shop/tokens.css

cat > site/index.html <<'HTML'
<!DOCTYPE html>
<html lang="pl"><head><meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=shop/">
<title>Mister Szoko</title>
<link rel="canonical" href="shop/">
</head><body><p><a href="shop/">Mister Szoko</a></p></body></html>
HTML

echo "══ 3/6 · contrôles AVANT de toucher au site ═══════════════════════════"
# Le rail est la seule source de vérité de ce qui doit exister. Un écran ajouté
# au menu et oublié à la copie casse ICI, pas devant un client.
php -r '
  require_once "mrszoko/backoffice/console.php";
  $manque = [];
  foreach (console_sections(["role" => "Centrala"]) as $items) {
    foreach (array_keys($items) as $f) {
      if (!is_file("site/backoffice/" . $f)) $manque[] = $f;
    }
  }
  if ($manque) { fwrite(STDERR, "  ÉCRANS DU RAIL NON ASSEMBLÉS : " . implode(", ", $manque) . "\n"); exit(1); }
  echo "  rail : chaque entrée du menu a son fichier\n";
'
test -s site/backoffice/login.php || { echo "  login.php absent : personne ne pourrait entrer"; exit 1; }
grep -q -- '--choco-700' site/shop/tokens.css || { echo "  tokens.css incomplet"; exit 1; }
# Les trois pièces des boutons de statut doivent partir ENSEMBLE : deux sur
# trois donnent un écran qui répond 200 avec des pastilles hors d'atteinte du
# pouce, et personne ne le voit avant de s'en servir.
grep -q 'wsm_order_etapy' site/backoffice/api/invoice.php \
  && grep -q 'name="status"' site/backoffice/zamowienia.php \
  && grep -q '\.etap' site/backoffice/console.css \
  || { echo "  boutons de statut incomplets dans l'assemblage"; exit 1; }
echo "  login, tokens, boutons de statut : présents"

echo "══ 4/6 · mise en place dans $DEPLOY_DIR ═══════════════════════════════"
# On NE SUPPRIME RIEN : les photos envoyées depuis la console vivent ici et ne
# sont pas dans le dépôt. C'est la même règle que le rsync du workflow.
# config.local.php n'est pas dans le dépôt : il survit forcément.
cp -a site/. "$DEPLOY_DIR"/
# Le propriétaire du serveur web, sinon la console ne peut plus écrire ses
# photos ni ses journaux.
if id -u www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$DEPLOY_DIR" 2>/dev/null || true
fi
echo "  copié"

echo "══ 5/6 · contenu éditorial (SQL) ══════════════════════════════════════"
# PAR LE CLIENT mysql, pas par migrate.php : le php en ligne de commande de ce
# serveur n'a pas pdo_mysql. « --sync-content » n'y a JAMAIS rien fait, et
# l'échec était avalé — trois déploiements verts n'ont rien synchronisé.
#
# Les identifiants sont LUS dans config.local.php, jamais redemandés : ils
# passeraient sinon par l'historique du shell.
CFG="$DEPLOY_DIR/backoffice/api/config.local.php"
if [ -f "$CFG" ]; then
  OPT="$TRAVAIL/my.cnf"
  ( umask 077; php -r '
      $c = require $argv[1];
      $m = $c["mysql"] ?? [];
      if (($c["engine"] ?? "") !== "mysql" || ($m["user"] ?? "") === "") exit(2);
      printf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
             $m["user"], $m["pass"] ?? "", $m["host"] ?? "127.0.0.1", $m["port"] ?? "3306");
    ' "$CFG" > "$OPT" ) && LU=1 || LU=0
  BASE=$(php -r '$c = require $argv[1]; echo $c["mysql"]["name"] ?? "mrszoko";' "$CFG")
  if [ "$LU" = 1 ]; then
    php "$DEPLOY_DIR/backoffice/api/migrate.php" --sync-content-sql > "$TRAVAIL/sync.sql"
    echo "  $(wc -l < "$TRAVAIL/sync.sql") instructions, base « $BASE »"
    if mysql --defaults-extra-file="$OPT" --default-character-set=utf8mb4 "$BASE" \
         < "$TRAVAIL/sync.sql" 2> "$TRAVAIL/sync.err"; then
      echo "  contenu éditorial : synchronisé"
    else
      echo "  ^ ATTENTION : synchronisation échouée"
      head -3 "$TRAVAIL/sync.err" | sed 's/^/    MySQL: /'
    fi
  else
    echo "  ^ config.local.php ne décrit pas MySQL — contenu NON synchronisé"
  fi
else
  echo "  ^ $CFG absent — contenu NON synchronisé (le nouveau bloc B2B n'apparaîtra pas)"
fi

echo "══ 6/6 · l'effet, sur les pages réelles ═══════════════════════════════"
fail=0
SH="http://localhost/mrszoko/shop"
BOU="http://localhost/mrszoko/backoffice"

H=$(curl -skL "$SH/")
case "$H" in
  *'Strefa pro'*|*'40 kg'*) echo "  ^ ZLE : stary blok « Strefa pro » nadal na stronie"; fail=1;;
  *'id="pro"'*)             echo "  blok B2B jest na stronie";;
  *)                        echo "  ^ ATTENTION : brak bloku B2B"; fail=1;;
esac
case "$H" in
  *'kontakt?temat=wspolpraca'*|*'kontakt&#63;temat=wspolpraca'*)
    echo "  przycisk prowadzi do formularza z tematem";;
  *) echo "  ^ ZLE : przycisk B2B nie prowadzi do formularza"; fail=1;;
esac
case "$(curl -skL "$SH/kontakt?temat=wspolpraca")" in
  *'value="wspolpraca" selected'*) echo "  formularz otwiera się na « Współpraca / hurt »";;
  *) echo "  ^ ZLE : formularz ignoruje temat z adresu"; fail=1;;
esac
for e in login.php zamowienia.php superadmin.php dostawa.php; do
  C=$(curl -skL -o /dev/null -w '%{http_code}' "$BOU/$e")
  [ "$C" = 200 ] && echo "  $e : 200" || { echo "  ^ ZLE : $e odpowiada $C"; fail=1; }
done
curl -skL "$BOU/console.css" | grep -q '\.etap' \
  && echo "  console.css ze stylem etapów : dojechał" \
  || { echo "  ^ ZLE : console.css bez stylu etapów — przyciski będą za małe"; fail=1; }

echo
if [ "$fail" = 0 ]; then
  echo "TOUT EST VERT — https://185.180.206.46/mrszoko/shop/"
else
  echo "DES CONTRÔLES ONT ÉCHOUÉ — voir ci-dessus"; exit 1
fi
