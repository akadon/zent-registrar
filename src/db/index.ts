import { drizzle } from 'drizzle-orm/postgres-js';
import postgres from 'postgres';
import * as schema from './schema.js';

const DATABASE_URL = process.env.REGISTRAR_DATABASE_URL || process.env.DATABASE_URL || 'postgresql://yxc:yxc-prod-2026-secure@postgres.zent:5432/yxc';

const client = postgres(DATABASE_URL, { max: 10 });
export const db = drizzle(client, { schema });
export { schema };
