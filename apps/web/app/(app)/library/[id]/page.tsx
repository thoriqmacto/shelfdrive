import BookDetailClient from "./BookDetailClient";

export const metadata = { title: "Book" };

type Params = Promise<{ id: string }>;

export default async function BookDetailPage({ params }: { params: Params }) {
    const { id } = await params;
    return <BookDetailClient id={Number(id)} />;
}
