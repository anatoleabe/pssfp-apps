import { LegalDocument } from '@/components/LegalDocument';

export const metadata = {
  title: 'Politique de confidentialité',
  description:
    'Politique de confidentialité et de protection des données personnelles des candidats du PSSFP.',
};

// NOTE (Anatole) : contenu de référence à faire valider par le service
// juridique / la direction du PSSFP avant la mise en production définitive.
// Vérifier notamment l'adresse de contact DPO et la durée de conservation.
export default function ConfidentialitePage(): JSX.Element {
  return (
    <LegalDocument title="Politique de confidentialité" updatedAt="2026-07-03">
      <p>
        Le PSSFP attache une grande importance à la protection des données personnelles des
        candidats. La présente politique décrit les données collectées via le portail de
        candidature, les finalités de leur traitement et les droits dont vous disposez.
      </p>

      <h2>1. Responsable du traitement</h2>
      <p>
        Le responsable du traitement est le Programme Supérieur de Spécialisation en Finances
        Publiques (PSSFP), Campus de Messa, Yaoundé — Cameroun. Pour toute question relative à
        vos données, vous pouvez écrire à <a href="mailto:contact@pssfp.net">contact@pssfp.net</a>.
      </p>

      <h2>2. Données collectées</h2>
      <ul>
        <li>Données d'identité : civilité, nom, prénom, date et lieu de naissance, genre, nationalité, situation matrimoniale.</li>
        <li>Données de contact : numéro(s) de téléphone, adresse postale, ville, pays, adresse électronique (facultative).</li>
        <li>Données académiques et professionnelles : diplôme, établissement, année d'obtention, situation professionnelle, employeur.</li>
        <li>Photo d'identité téléversée par le candidat.</li>
        <li>Données techniques minimales de sécurité : adresse IP et horodatage lors de la connexion et de la soumission.</li>
      </ul>

      <h2>3. Finalités</h2>
      <ul>
        <li>Création et gestion du compte candidat.</li>
        <li>Constitution, instruction et évaluation du dossier de candidature par le comité d'admission.</li>
        <li>Communication avec le candidat (confirmation de soumission, demandes de complément, décision).</li>
        <li>Prévention de la fraude et sécurisation du portail.</li>
      </ul>

      <h2>4. Base légale</h2>
      <p>
        Les traitements reposent sur le consentement du candidat (recueilli à l'inscription) et
        sur l'intérêt légitime du PSSFP à organiser ses procédures de recrutement et à en assurer
        la sécurité.
      </p>

      <h2>5. Destinataires</h2>
      <p>
        Vos données sont destinées aux services habilités du PSSFP et aux membres du comité
        d'admission. Elles ne sont ni vendues, ni cédées à des tiers à des fins commerciales.
        Certains prestataires techniques (hébergement, envoi de SMS et d'emails, protection
        anti-robot) peuvent traiter des données pour notre compte, dans le strict cadre de leur
        mission.
      </p>

      <h2>6. Durée de conservation</h2>
      <p>
        Les données des candidats sont conservées pendant la durée de la campagne et jusqu'à 24
        mois après la publication des résultats, puis archivées ou supprimées, sauf obligation
        légale contraire ou admission effective au programme.
      </p>

      <h2>7. Sécurité</h2>
      <p>
        Le PSSFP met en œuvre des mesures techniques et organisationnelles appropriées : chiffrement
        des échanges (HTTPS), stockage privé des pièces (photo, récépissé) via des liens signés à
        durée limitée, contrôle des accès et journalisation des actions sensibles.
      </p>

      <h2>8. Vos droits</h2>
      <p>
        Vous disposez d'un droit d'accès, de rectification, d'effacement, de limitation et de
        portabilité de vos données, ainsi que du droit de retirer votre consentement. Pour
        exercer ces droits, écrivez à <a href="mailto:contact@pssfp.net">contact@pssfp.net</a> en
        précisant votre numéro de dossier. Le retrait du consentement peut empêcher la poursuite
        de l'instruction de votre candidature.
      </p>

      <h2>9. Cookies</h2>
      <p>
        Le portail n'utilise que des cookies strictement nécessaires à son fonctionnement
        (maintien de session sécurisée, protection anti-robot). Aucun cookie de suivi publicitaire
        n'est déposé.
      </p>
    </LegalDocument>
  );
}
