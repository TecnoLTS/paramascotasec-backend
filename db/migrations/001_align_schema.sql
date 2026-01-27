BEGIN;

-- Product table
ALTER TABLE "Product" RENAME COLUMN "legacyId" TO legacy_id;
ALTER TABLE "Product" RENAME COLUMN categoria TO category;
ALTER TABLE "Product" RENAME COLUMN nombre TO name;
ALTER TABLE "Product" RENAME COLUMN genero TO gender;
ALTER TABLE "Product" RENAME COLUMN nuevo TO is_new;
ALTER TABLE "Product" RENAME COLUMN oferta TO is_sale;
ALTER TABLE "Product" RENAME COLUMN precio TO price;
ALTER TABLE "Product" RENAME COLUMN precio_original TO original_price;
ALTER TABLE "Product" RENAME COLUMN marca TO brand;
ALTER TABLE "Product" RENAME COLUMN vendido TO sold;
ALTER TABLE "Product" RENAME COLUMN cantidad TO quantity;
ALTER TABLE "Product" RENAME COLUMN descripcion TO description;
ALTER TABLE "Product" RENAME COLUMN accion TO action;
ALTER TABLE "Product" RENAME COLUMN fecha_de_creacion TO created_at;
ALTER TABLE "Product" RENAME COLUMN fecha_de_actualziacion TO updated_at;
ALTER TABLE "Product" ADD COLUMN cost numeric(10,2) DEFAULT 0 NOT NULL;

-- Image table
ALTER TABLE "Image" RENAME COLUMN "productId" TO product_id;

-- Variation table
ALTER TABLE "Variation" RENAME COLUMN "colorCode" TO color_code;
ALTER TABLE "Variation" RENAME COLUMN "colorImage" TO color_image;
ALTER TABLE "Variation" RENAME COLUMN "productId" TO product_id;

-- Order table
ALTER TABLE "Order" RENAME COLUMN "userId" TO user_id;
ALTER TABLE "Order" RENAME COLUMN "createdAt" TO created_at;
ALTER TABLE "Order" ADD COLUMN shipping_address jsonb;
ALTER TABLE "Order" ADD COLUMN billing_address jsonb;
ALTER TABLE "Order" ADD COLUMN payment_method text;

-- OrderItem table
ALTER TABLE "OrderItem" RENAME COLUMN "orderId" TO order_id;
ALTER TABLE "OrderItem" RENAME COLUMN "productId" TO product_id;
ALTER TABLE "OrderItem" ADD COLUMN product_name text;
ALTER TABLE "OrderItem" ADD COLUMN product_image text;

-- User table
ALTER TABLE "User" RENAME COLUMN "createdAt" TO created_at;
ALTER TABLE "User" RENAME COLUMN "updatedAt" TO updated_at;
ALTER TABLE "User" ADD COLUMN email_verified boolean DEFAULT false NOT NULL;
ALTER TABLE "User" ADD COLUMN verification_token text;
ALTER TABLE "User" ADD COLUMN role text DEFAULT 'customer' NOT NULL;

-- Indexes
DROP INDEX IF EXISTS "Product_legacyId_key";
CREATE UNIQUE INDEX IF NOT EXISTS "Product_legacy_id_key" ON public."Product" USING btree (legacy_id);

-- Foreign keys
ALTER TABLE ONLY public."Image" DROP CONSTRAINT IF EXISTS "Image_productId_fkey";
ALTER TABLE ONLY public."Image"
    ADD CONSTRAINT "Image_product_id_fkey" FOREIGN KEY (product_id) REFERENCES public."Product"(id) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public."OrderItem" DROP CONSTRAINT IF EXISTS "OrderItem_orderId_fkey";
ALTER TABLE ONLY public."OrderItem" DROP CONSTRAINT IF EXISTS "OrderItem_productId_fkey";
ALTER TABLE ONLY public."OrderItem"
    ADD CONSTRAINT "OrderItem_order_id_fkey" FOREIGN KEY (order_id) REFERENCES public."Order"(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE ONLY public."OrderItem"
    ADD CONSTRAINT "OrderItem_product_id_fkey" FOREIGN KEY (product_id) REFERENCES public."Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE ONLY public."Order" DROP CONSTRAINT IF EXISTS "Order_userId_fkey";
ALTER TABLE ONLY public."Order"
    ADD CONSTRAINT "Order_user_id_fkey" FOREIGN KEY (user_id) REFERENCES public."User"(id) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public."Variation" DROP CONSTRAINT IF EXISTS "Variation_productId_fkey";
ALTER TABLE ONLY public."Variation"
    ADD CONSTRAINT "Variation_product_id_fkey" FOREIGN KEY (product_id) REFERENCES public."Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT;

COMMIT;
