export type Organization = {
    provider: string;
    id: number;
    url: string;
    name: string | null;
    rating: string | null;
    ratings_count: number | null;
    reviews_count: number | null;
    status: string;
    error: string | null;
    synced_at: string | null;
};
export type Review = {
    id: number;
    author: string;
    published_at: string;
    text: string;
    rating: number;
};
export type Detail = {
    statistics: {
        distribution: { rating: number; count: number }[];
        source_limited: boolean;
    };
    history: {
        id: number;
        status: string;
        collected: number;
        updated_at: string;
        error: string | null;
    }[];
    organization: Organization;
    run: { collected: number } | null;
    reviews: {
        data: Review[];
        current_page: number;
        last_page: number;
        total: number;
    };
};
