const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const SyncGroupsController = async (ctx, data, io,socket) => {
  await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
};

module.exports = { SyncGroupsController };