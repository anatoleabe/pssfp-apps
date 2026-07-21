import { LegalDocument } from '@/components/LegalDocument';

export const metadata = {
  title: "Conditions générales d'utilisation",
  description:
    "Conditions générales d'utilisation du portail de candidature en ligne du PSSFP.",
};

// NOTE (Anatole) : contenu de référence à faire valider par le service
// juridique / la direction du PSSFP avant la mise en production définitive.
export default function CguPage(): JSX.Element {
  return (
    <LegalDocument title="Conditions générales d'utilisation" updatedAt="2026-07-03">
      <p>
        Les présentes conditions générales d'utilisation (les « CGU ») régissent l'accès et
        l'usage du portail de candidature en ligne du Programme Supérieur de Spécialisation en
        Finances Publiques (le « PSSFP »), accessible à l'adresse{' '}
        <span className="whitespace-nowrap">apply.pssfp.org</span>. En créant un compte
        candidat, vous reconnaissez avoir lu et accepté sans réserve les présentes CGU.
      </p>

      <h2>1. Objet</h2>
      <p>
        Le portail permet à toute personne remplissant les conditions d'admission de constituer
        et de soumettre en ligne un dossier de candidature à une campagne de recrutement du
        PSSFP. Il ne constitue ni une promesse d'admission, ni un engagement du PSSFP à donner
        une suite favorable à une candidature.
      </p>

      <h2>2. Création et sécurité du compte</h2>
      <ul>
        <li>
          L'identifiant du candidat est son numéro de téléphone au format international ; le mot
          de passe est un code confidentiel à 6 chiffres (PIN) choisi par le candidat.
        </li>
        <li>
          Le candidat est seul responsable de la confidentialité de son PIN et de toutes les
          actions effectuées depuis son compte.
        </li>
        <li>
          En cas d'oubli du PIN, une procédure de réinitialisation par code SMS est proposée. À
          défaut, le candidat peut contacter le PSSFP.
        </li>
      </ul>

      <h2>3. Exactitude des informations</h2>
      <p>
        Le candidat s'engage à fournir des informations exactes, complètes et à jour. Toute
        déclaration inexacte, tout document falsifié ou toute usurpation d'identité entraîne le
        rejet immédiat de la candidature, sans préjudice d'éventuelles poursuites.
      </p>

      <h2>4. Frais de dossier</h2>
      <p>
        La finalisation d'une candidature suppose le paiement des frais de dossier de 50 000
        FCFA en agence CREMINCAM, en mentionnant le numéro de dossier communiqué lors de la
        soumission. Ces frais couvrent l'instruction administrative du dossier et ne sont pas
        remboursables, quelle que soit la décision du comité d'admission.
      </p>

      <h2>5. Traitement de la candidature</h2>
      <p>
        Les candidatures sont examinées par le comité d'admission du PSSFP selon ses propres
        critères. Le candidat est informé par voie électronique et/ou téléphonique de l'évolution
        et de la décision finale relative à son dossier.
      </p>

      <h2>6. Données personnelles</h2>
      <p>
        Le traitement des données personnelles collectées via le portail est décrit dans notre{' '}
        <a href="/confidentialite">politique de confidentialité</a>, que le candidat est invité à
        consulter. L'utilisation du portail vaut acceptation de cette politique.
      </p>

      <h2>7. Disponibilité et responsabilité</h2>
      <p>
        Le PSSFP s'efforce d'assurer la disponibilité du portail mais ne peut être tenu
        responsable des interruptions liées à la maintenance, à un cas de force majeure ou à des
        facteurs indépendants de sa volonté (réseau, hébergement, terminal du candidat). Il est
        recommandé de ne pas attendre la date limite pour soumettre son dossier.
      </p>

      <h2>8. Propriété intellectuelle</h2>
      <p>
        L'ensemble des éléments du portail (marques, logos, textes, mise en page) est la propriété
        du PSSFP. Toute reproduction non autorisée est interdite.
      </p>

      <h2>9. Modification des CGU</h2>
      <p>
        Le PSSFP se réserve le droit de modifier les présentes CGU à tout moment. La version
        applicable est celle en vigueur au moment de l'utilisation du portail.
      </p>

      <h2>10. Droit applicable</h2>
      <p>
        Les présentes CGU sont soumises au droit camerounais. Tout litige relève de la compétence
        des juridictions de Yaoundé, à défaut de résolution amiable.
      </p>
    </LegalDocument>
  );
}
