import { Head, Link } from '@inertiajs/react';
import GuestLayout from '../Layouts/GuestLayout';

export default function Privacy() {
    return <GuestLayout><Head title="Privacy" /><h1 className="text-2xl font-black">Privacy and Gmail statements</h1><div className="mt-4 space-y-4 text-sm leading-6 text-slate-700">
        <p>Connecting Gmail is optional and separate from Google sign-in. POSPilot requests <code>https://www.googleapis.com/auth/gmail.readonly</code> to search for provider statement messages and retrieve spreadsheet attachments.</p>
        <p>Google classifies this as a restricted Gmail scope. It technically permits an app to view Gmail messages and settings broadly. Provider sender, date, and attachment filters limit what POSPilot searches and processes; they do not narrow Google's OAuth permission itself.</p>
        <p>POSPilot is designed to process only statement emails matching the providers and sender addresses you configure. Unrelated email content is not used for POSPilot's service. POSPilot does not send email from your mailbox.</p>
        <p>Gmail access and refresh tokens are stored server-side in encrypted storage and are never sent to the frontend. Disconnecting Gmail stops future searches and removes the local token and any pending encrypted statement attachments. Google token revocation is also requested.</p>
        <p>Gmail automation is a hackathon beta. General production use requires Google's restricted-scope verification and any applicable independent security assessment.</p>
        <p><a className="font-bold text-brand-accent" href="https://developers.google.com/workspace/gmail/api/auth/scopes" target="_blank" rel="noreferrer">Google's Gmail scope documentation</a></p>
        <Link href="/" className="inline-flex min-h-11 items-center font-bold text-brand-accent">Back to POSPilot</Link>
    </div></GuestLayout>;
}
