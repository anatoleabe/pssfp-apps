{{--
    Un champ numérique du navigateur change de valeur d'une unité à la molette (champ actif) et aux
    flèches haut et bas. Un versement de 250 000 FCFA a ainsi été enregistré à 249 999 (24/11/2026).
    La valeur d'un champ numérique ne change donc que par la frappe, sur tous les écrans du panneau.
--}}
<script>
    (() => {
        const champNombre = (cible) => cible instanceof HTMLInputElement && cible.type === 'number';

        document.addEventListener('wheel', (evenement) => {
            if (champNombre(evenement.target) && document.activeElement === evenement.target) {
                evenement.target.blur();
            }
        }, { capture: true, passive: true });

        document.addEventListener('keydown', (evenement) => {
            if (champNombre(evenement.target) && (evenement.key === 'ArrowUp' || evenement.key === 'ArrowDown')) {
                evenement.preventDefault();
            }
        }, { capture: true });
    })();
</script>
