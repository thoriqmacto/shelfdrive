import ListDetailClient from "./ListDetailClient";

export const metadata = { title: "List" };

type Params = Promise<{ id: string }>;

export default async function ListDetailPage({ params }: { params: Params }) {
    const { id } = await params;
    return <ListDetailClient id={Number(id)} />;
}
