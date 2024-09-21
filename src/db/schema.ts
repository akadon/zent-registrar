import { pgTable, text, boolean, integer, jsonb, timestamp, real } from 'drizzle-orm/pg-core';

export const servers = pgTable('registry_servers', {
  id: text('id').primaryKey(),
  name: text('name').notNull(),
  domain: text('domain').notNull().unique(),
  apiUrl: text('api_url').notNull(),
  wsUrl: text('ws_url').notNull(),
  
  // Protocol support
  protocols: jsonb('protocols').notNull().default([]),
  
  // Capabilities
  capabilities: jsonb('capabilities').notNull().default({}),
  
  // Metadata
  description: text('description').default(''),
  icon: text('icon'),
  banner: text('banner'),
  tags: jsonb('tags').notNull().default([]),
  languages: jsonb('languages').notNull().default(['en']),
  
  // Stats
  userCount: integer('user_count').default(0),
  channelCount: integer('channel_count').default(0),
  
  // Trust
  verified: boolean('verified').default(false),
  publicKey: text('public_key'),
  reputation: real('reputation').default(50),
  ratingCount: integer('rating_count').default(0),
  ratingSum: integer('rating_sum').default(0),
  
  // Status
  status: text('status').default('online'),
  lastSeen: timestamp('last_seen').defaultNow(),
  lastHealthCheck: timestamp('last_health_check'),
  
  // Registration
  isPublic: boolean('is_public').default(true),
  requiresInvite: boolean('requires_invite').default(false),
  contactEmail: text('contact_email'),
  privacyPolicyUrl: text('privacy_policy_url'),
  termsOfServiceUrl: text('terms_of_service_url'),
  
  // Auth
  serverToken: text('server_token').notNull(),
  
  createdAt: timestamp('created_at').defaultNow(),
  updatedAt: timestamp('updated_at').defaultNow(),
});

export const serverRatings = pgTable('registry_ratings', {
  id: text('id').primaryKey(),
  serverId: text('server_id').notNull(),
  userId: text('user_id').notNull(),
  rating: integer('rating').notNull(),
  comment: text('comment'),
  createdAt: timestamp('created_at').defaultNow(),
});
