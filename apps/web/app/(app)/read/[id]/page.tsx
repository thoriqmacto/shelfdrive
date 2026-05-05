import ReaderShell from "./ReaderShell";

export const metadata = { title: "Reader" };

type Params = Promise<{ id: string }>;

export default async function ReadPage({ params }: { params: Params }) {
    const { id } = await params;
    return <ReaderShell id={Number(id)} />;
}
