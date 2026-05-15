FROM node:20-alpine

# Build tools for native module compilation (required by @whiskeysockets/baileys deps)
RUN apk add --no-cache python3 make g++ git

WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev

COPY . .

RUN mkdir -p sessions

EXPOSE 3001

CMD ["node", "index.js"]
